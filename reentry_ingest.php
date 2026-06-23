<?php
/**
 * reentry_ingest.php  -  Tramo 1 (SST): análisis de reentrada sobre datos públicos.
 *
 * Baja un grupo GP de CelesTrak (JSON), calcula el perigeo de cada objeto, y para los
 * candidatos a reentrada (perigeo bajo) crea o actualiza un incidente con la ventana
 * estimada, la huella como banda de latitud y el solapamiento con el área de interés.
 *
 * Cron sugerido (diario):
 *   15 5 * * * /usr/local/bin/ea-php83 /home/USUARIO/public_html/reentry_ingest.php >/dev/null 2>&1
 * Prueba en seco:
 *   curl "https://newspace.live/reentry_ingest.php?token=TU_TOKEN&dry=1"
 *
 * HONESTO: estimación de orden de magnitud, no predicción precisa de hora ni punto de
 * impacto. La ventana real depende de densidad atmosférica, actitud y área/masa.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/reentry_lib.php';

$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
function fail(string $m, int $c = 500): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

$isCli = (PHP_SAPI === 'cli');
$dry = $isCli ? in_array('dry', $argv ?? [], true) : isset($_GET['dry']);
if (!$isCli) { if (!hash_equals((string)($cfg['ingest']['token'] ?? ''), (string)($_GET['token'] ?? ''))) fail('forbidden', 403); }
if (!function_exists('curl_init')) fail('cURL not available on this server');

$re = $cfg['reentry'] ?? [];
$url = (string)($re['url'] ?? 'https://celestrak.org/NORAD/elements/gp.php?GROUP=active&FORMAT=json');
$perigeeMax = (float)($re['perigee_km'] ?? 300.0);
$aoiMin = (float)($re['aoi'][0] ?? -35.0);
$aoiMax = (float)($re['aoi'][1] ?? -30.0);

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'newspace.live SSA reentry']);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($resp === false || $code >= 400) fail('GP fetch failed (HTTP ' . $code . ')');
$rows = json_decode((string)$resp, true);
if (!is_array($rows)) fail('unexpected GP response (expected OMM JSON array)');

function gv($r, array $keys) { foreach ($keys as $k) { if (isset($r[$k]) && $r[$k] !== '') return $r[$k]; } return null; }

$cands = [];
foreach ($rows as $r) {
    if (!is_array($r)) continue;
    $mm = gv($r, ['MEAN_MOTION', 'mean_motion']); if ($mm === null) continue;
    $ec = (float)(gv($r, ['ECCENTRICITY', 'eccentricity']) ?? 0);
    $inc = (float)(gv($r, ['INCLINATION', 'inclination']) ?? 0);
    $pe = re_perigee_km((float)$mm, $ec);
    if ($pe >= $perigeeMax || $pe < 0) continue;
    $latReach = re_lat_reach($inc);
    $aoi = re_aoi_overlap($inc, $aoiMin, $aoiMax);
    [$band, $days] = re_lifetime_band($pe);
    $cands[] = [
        'id' => (string)(gv($r, ['NORAD_CAT_ID', 'norad_cat_id']) ?? '?'),
        'name' => trim((string)(gv($r, ['OBJECT_NAME', 'object_name']) ?? 'UNKNOWN')),
        'perigee' => round($pe, 1), 'apogee' => round(re_apogee_km((float)$mm, $ec), 1),
        'incl' => round($inc, 2), 'latReach' => round($latReach, 1),
        'band' => $band, 'days' => $days, 'aoi' => $aoi,
        'severity' => re_severity($pe, $aoi),
    ];
}

if ($dry) {
    usort($cands, fn($a, $b) => $a['perigee'] <=> $b['perigee']);
    echo json_encode(['ok' => true, 'dry_run' => true, 'scanned' => count($rows), 'candidates' => count($cands), 'sample' => array_slice($cands, 0, 15)], JSON_PRETTY_PRINT);
    exit;
}

function st_notify(array $cfg, string $text): void {
    $t = $cfg['telegram'] ?? []; $token = (string)($t['token'] ?? ''); $chat = (string)($t['chat_id'] ?? '');
    if ($token === '' || $chat === '' || !function_exists('curl_init')) return;
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => $text]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch); curl_close($ch);
}

$RANK = ['critical' => 0, 'high' => 1, 'elevated' => 2, 'routine' => 3];
try { $db = ssa_db($cfg); } catch (Throwable $e) { fail('db connection failed (check config.php / pdo_mysql)'); }

$upserted = 0; $escalated = 0;
$selSt  = $db->prepare('SELECT state FROM app_incident WHERE id=?');
$ins    = $db->prepare('INSERT INTO app_incident (id,domain,object,kind,severity,priority,recommended_action,state,metric,tca_utc,opened_utc,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
$upd    = $db->prepare('UPDATE app_incident SET object=?,severity=?,priority=?,recommended_action=?,metric=?,tca_utc=?,updated_at=NOW() WHERE id=?');
$audit  = $db->prepare('INSERT INTO app_incident_audit (incident_id,ts,actor_id,actor_name,from_state,to_state,note) VALUES (?,NOW(),NULL,?,?,?,?)');
$esc    = $db->prepare("UPDATE app_incident SET state='escalated',updated_at=NOW() WHERE id=? AND state='new'");
$actLog = $db->prepare('INSERT INTO app_activity (user_id,user_name,action,detail,ip,ts) VALUES (NULL,?,?,?,?,NOW())');

foreach ($cands as $c) {
    $action = ($c['severity'] === 'critical' || $c['severity'] === 'high') ? 'escalate' : ($c['severity'] === 'elevated' ? 'monitor' : 'ignore');
    $days = $c['days'] !== null ? (float)$c['days'] : 30.0;
    $priority = $RANK[$c['severity']] * 1000 + min(500, $days);
    $tcaSql = gmdate('Y-m-d H:i:s', time() + (int)round($days * 86400));
    $id = 'ssa:reentry:' . $c['id'];
    $metric = 'perigeo ' . $c['perigee'] . ' km | apogeo ' . $c['apogee'] . ' km | i ' . $c['incl']
            . ' | huella +/-' . $c['latReach'] . ' lat | ~' . $c['band'] . ($c['aoi'] ? ' | cruza AOI' : '') . ' (estimacion)';

    $selSt->execute([$id]); $exists = $selSt->fetch();
    if (!$exists) {
        $ins->execute([$id, 'ssa', $c['name'], 'reentrada', $c['severity'], $priority, $action, 'new', $metric, $tcaSql]);
        $audit->execute([$id, 'reentry', null, 'new', 'perigee ' . $c['perigee'] . ' km']);
    } else {
        $upd->execute([$c['name'], $c['severity'], $priority, $action, $metric, $tcaSql, $id]);
    }
    $upserted++;

    if ($c['severity'] === 'critical') {
        $selSt->execute([$id]); $cur = $selSt->fetch();
        if ($cur && $cur['state'] === 'new') {
            $esc->execute([$id]);
            if ($esc->rowCount() > 0) {
                $audit->execute([$id, 'reentry', 'new', 'escalated', 'perigee ' . $c['perigee'] . ' km' . ($c['aoi'] ? ' (AOI)' : '')]);
                $actLog->execute(['reentry', 'reentry_escalate', $id . ' perigee=' . $c['perigee'], 'cron']);
                $escalated++;
                st_notify($cfg, "Reentrada inminente\n" . $c['name'] . "\nperigeo " . $c['perigee'] . " km, i " . $c['incl']
                    . ($c['aoi'] ? "\nhuella cruza el area de interes" : ""));
            }
        }
    }
}

echo json_encode(['ok' => true, 'source' => 'reentry', 'scanned' => count($rows), 'candidates' => count($cands), 'upserted' => $upserted, 'escalated' => $escalated]);
