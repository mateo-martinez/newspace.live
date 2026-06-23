<?php
/**
 * neocp_ingest.php  -  Tramo 1 (NEO): respuesta rápida sobre la NEO Confirmation Page del MPC.
 *
 * Baja la NEOCP (descubrimientos nuevos sin confirmar), y por cada candidato crea o actualiza
 * un incidente (dominio neo) marcando si OALM puede observarlo (altura de culminación) y con
 * qué prioridad de seguimiento. Es el servicio de respuesta rápida que pide la ESA para NEO.
 *
 * Cron sugerido (cada 3 h; horas explícitas para no romper este comentario):
 *   45 0,3,6,9,12,15,18,21 * * * /usr/local/bin/ea-php83 /home/USUARIO/public_html/neocp_ingest.php >/dev/null 2>&1
 * Prueba en seco:
 *   curl "https://newspace.live/neocp_ingest.php?token=TU_TOKEN&dry=1"
 *
 * HONESTO: la altura de culminación es la altura máxima posible, condición necesaria de
 * observabilidad, no una solución de apuntado ni ventana horaria.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/neocp_lib.php';

$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
function fail(string $m, int $c = 500): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

$isCli = (PHP_SAPI === 'cli');
$dry = $isCli ? in_array('dry', $argv ?? [], true) : isset($_GET['dry']);
if (!$isCli) { if (!hash_equals((string)($cfg['ingest']['token'] ?? ''), (string)($_GET['token'] ?? ''))) fail('forbidden', 403); }
if (!function_exists('curl_init')) fail('cURL not available on this server');

$n = $cfg['neocp'] ?? [];
$url = (string)($n['url'] ?? 'https://www.minorplanetcenter.net/Extended_Files/neocp.json');
$lat = (float)($n['lat'] ?? -34.7553);
$minAlt = (float)($n['min_alt'] ?? 20.0);
$magLimit = (float)($n['mag_limit'] ?? 21.0);
$minScore = (float)($n['min_score'] ?? 50.0);

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'newspace.live NEO neocp']);
$resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
if ($resp === false || $code >= 400) fail('NEOCP fetch failed (HTTP ' . $code . ')');
$rows = json_decode((string)$resp, true);
if (!is_array($rows)) fail('unexpected NEOCP response (expected JSON array)');

function nv($r, array $keys) { foreach ($keys as $k) { if (isset($r[$k]) && $r[$k] !== '') return $r[$k]; } return null; }

$items = [];
foreach ($rows as $r) {
    if (!is_array($r)) continue;
    $desig = nv($r, ['Temp_Desig', 'Designation', 'desig', 'TempDesig']); if ($desig === null) continue;
    $score = (float)(nv($r, ['Score', 'score']) ?? 0);
    $decRaw = nv($r, ['Decl', 'Decl.', 'Dec', 'declination', 'DEC']);
    $dec = $decRaw !== null ? (float)$decRaw : null;
    $vRaw = nv($r, ['V', 'Vmag', 'v', 'mag']); $vmag = $vRaw !== null ? (float)$vRaw : null;
    $notSeen = nv($r, ['Not_Seen_dys', 'NotSeen', 'not_seen_dys']);
    $nobs = nv($r, ['NObs', 'nobs']);
    $arc = nv($r, ['Arc', 'arc']);
    $culm = $dec !== null ? neocp_culmination_alt($lat, $dec) : null;
    $obs = $dec !== null ? neocp_observable($lat, $dec, $minAlt) : false;
    $items[] = [
        'desig' => (string)$desig, 'score' => $score, 'dec' => $dec, 'vmag' => $vmag,
        'culm' => $culm !== null ? round($culm, 1) : null, 'observable' => $obs,
        'notSeen' => $notSeen, 'nobs' => $nobs, 'arc' => $arc,
        'severity' => neocp_severity($score), 'action' => neocp_action($obs, $vmag, $magLimit),
    ];
}

if ($dry) {
    $kept = array_values(array_filter($items, fn($i) => $i['score'] >= $minScore));
    echo json_encode(['ok' => true, 'dry_run' => true, 'fetched' => count($rows), 'kept' => count($kept),
        'raw_keys' => count($rows) ? array_keys((array)$rows[0]) : [], 'sample' => array_slice($kept, 0, 15)], JSON_PRETTY_PRINT);
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

$upserted = 0; $targets = 0;
$selSt  = $db->prepare('SELECT state FROM app_incident WHERE id=?');
$ins    = $db->prepare('INSERT INTO app_incident (id,domain,object,kind,severity,priority,recommended_action,state,metric,obsv,opened_utc,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
$upd    = $db->prepare('UPDATE app_incident SET severity=?,priority=?,recommended_action=?,metric=?,obsv=?,updated_at=NOW() WHERE id=?');
$audit  = $db->prepare('INSERT INTO app_incident_audit (incident_id,ts,actor_id,actor_name,from_state,to_state,note) VALUES (?,NOW(),NULL,?,?,?,?)');
$actLog = $db->prepare('INSERT INTO app_activity (user_id,user_name,action,detail,ip,ts) VALUES (NULL,?,?,?,?,NOW())');

foreach ($items as $it) {
    if ($it['score'] < $minScore) continue;
    $obsv = $it['observable'] ? ($it['action'] === 'observe' ? 'tonight' : 'soon') : 'not_now';
    $priority = $RANK[$it['severity']] * 1000 + (100 - $it['score']);
    $id = 'neo:neocp:' . preg_replace('/[^A-Za-z0-9]+/', '', $it['desig']);
    $metric = 'score ' . round($it['score']) . ($it['vmag'] !== null ? ' | V ' . $it['vmag'] : '')
            . ($it['culm'] !== null ? ' | alt culm ' . $it['culm'] . ' deg' : '')
            . ($it['notSeen'] !== null ? ' | sin ver ' . $it['notSeen'] . ' d' : '')
            . ($it['arc'] !== null ? ' | arco ' . $it['arc'] : '') . ' (NEOCP)';

    $selSt->execute([$id]); $exists = $selSt->fetch();
    if (!$exists) {
        $ins->execute([$id, 'neo', $it['desig'], 'neocp', $it['severity'], $priority, $it['action'], 'new', $metric, $obsv]);
        $audit->execute([$id, 'mpc-neocp', null, 'new', 'NEOCP score ' . round($it['score'])]);
    } else {
        $upd->execute([$it['severity'], $priority, $it['action'], $metric, $obsv, $id]);
    }
    $upserted++;

    if ($it['observable'] && $it['score'] >= 80 && $it['action'] === 'observe') {
        $selSt->execute([$id]); $cur = $selSt->fetch();
        if ($cur && $cur['state'] === 'new') {
            $targets++;
            $actLog->execute(['mpc-neocp', 'neocp_target', $id . ' score=' . round($it['score']), 'cron']);
            st_notify($cfg, "Objetivo NEOCP observable\n" . $it['desig'] . "\nscore " . round($it['score'])
                . ($it['vmag'] !== null ? ", V " . $it['vmag'] : "") . ", culm " . $it['culm'] . " deg");
        }
    }
}

echo json_encode(['ok' => true, 'source' => 'neocp', 'fetched' => count($rows), 'upserted' => $upserted, 'targets' => $targets]);
