<?php
/**
 * swe_ingest.php  -  Tramo 1: ingesta de clima espacial de NOAA SWPC a incidentes.
 *
 * Baja las escalas NOAA G/R/S (noaa-scales.json), y por cada escala vigente crea o
 * actualiza un incidente en app_incident (dominio swe) con su impacto operativo,
 * auto-escala eventos severos y avisa por Telegram. Protegido por el token de ingesta.
 *
 * Cron sugerido (cada hora):
 *   30 * * * * /usr/local/bin/ea-php83 /home/USUARIO/public_html/swe_ingest.php >/dev/null 2>&1
 *
 * Prueba en seco:
 *   curl "https://newspace.live/swe_ingest.php?token=TU_TOKEN&dry=1"
 *
 * HONESTO: el sistema integra y traduce a impacto, no genera modelos ni pronosticos.
 * Las escalas y el impacto son los estandar de NOAA.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/swe_lib.php';

$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
function fail(string $m, int $c = 500): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

$isCli = (PHP_SAPI === 'cli');
$dry = $isCli ? in_array('dry', $argv ?? [], true) : isset($_GET['dry']);
if (!$isCli) {
    if (!hash_equals((string)($cfg['ingest']['token'] ?? ''), (string)($_GET['token'] ?? ''))) fail('forbidden', 403);
}
if (!function_exists('curl_init')) fail('cURL not available on this server');

$url = (string)($cfg['swe']['scales_url'] ?? 'https://services.swpc.noaa.gov/products/noaa-scales.json');
$minScale = (int)($cfg['swe']['min_scale'] ?? 2);

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_USERAGENT => 'newspace.live SWE ingest']);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($resp === false || $code >= 400) fail('swpc fetch failed (HTTP ' . $code . ')');

$data = json_decode($resp, true);
if (!is_array($data) || !isset($data['0'])) fail('unexpected response from swpc (expected noaa-scales json)');
$cur = $data['0']; // 0 = valores actuales/observados

$date = (string)($cur['DateStamp'] ?? gmdate('Y-m-d'));
$rows = [];
foreach (['G', 'S', 'R'] as $k) {
    if (!isset($cur[$k]['Scale'])) continue;
    $scale = (int)$cur[$k]['Scale'];
    $text = (string)($cur[$k]['Text'] ?? '');
    $sev = swe_severity($scale);
    $rows[] = ['k' => $k, 'scale' => $scale, 'text' => $text, 'severity' => $sev,
               'code' => $k . $scale, 'label' => swe_kind_label($k), 'impact' => swe_impact($k, $scale)];
}

if ($dry) {
    echo json_encode(['ok' => true, 'dry_run' => true, 'date' => $date, 'min_scale' => $minScale, 'scales' => $rows], JSON_PRETTY_PRINT);
    exit;
}

function st_notify(array $cfg, string $text): void {
    $t = $cfg['telegram'] ?? []; $token = (string)($t['token'] ?? ''); $chat = (string)($t['chat_id'] ?? '');
    if ($token === '' || $chat === '' || !function_exists('curl_init')) return;
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => $text]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
    curl_exec($ch); curl_close($ch);
}

try { $db = ssa_db($cfg); } catch (Throwable $e) { fail('db connection failed (check config.php / pdo_mysql)'); }

$upserted = 0; $escalated = 0;
$selSt  = $db->prepare('SELECT state FROM app_incident WHERE id=?');
$ins    = $db->prepare('INSERT INTO app_incident (id,domain,object,kind,severity,priority,recommended_action,state,metric,opened_utc,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())');
$upd    = $db->prepare('UPDATE app_incident SET object=?,severity=?,priority=?,recommended_action=?,metric=?,updated_at=NOW() WHERE id=?');
$audit  = $db->prepare('INSERT INTO app_incident_audit (incident_id,ts,actor_id,actor_name,from_state,to_state,note) VALUES (?,NOW(),NULL,?,?,?,?)');
$esc    = $db->prepare("UPDATE app_incident SET state='escalated',updated_at=NOW() WHERE id=? AND state='new'");
$actLog = $db->prepare('INSERT INTO app_activity (user_id,user_name,action,detail,ip,ts) VALUES (NULL,?,?,?,?,NOW())');

foreach ($rows as $r) {
    if ($r['severity'] === 'none' || $r['scale'] < $minScale) continue;
    $action = ($r['severity'] === 'critical' || $r['severity'] === 'high') ? 'escalate' : ($r['severity'] === 'elevated' ? 'monitor' : 'ignore');
    $id = 'swe:' . $r['k'] . ':' . $date;
    $object = $r['label'] . ' (' . $r['code'] . ')';
    $metric = $r['impact'] . ' | NOAA ' . $r['code'] . ($r['text'] !== '' ? ' ' . $r['text'] : '');
    $priority = swe_priority($r['severity']);

    $selSt->execute([$id]); $exists = $selSt->fetch();
    if (!$exists) {
        $ins->execute([$id, 'swe', $object, 'swe', $r['severity'], $priority, $action, 'new', $metric]);
        $audit->execute([$id, 'swpc', null, 'new', 'NOAA ' . $r['code']]);
    } else {
        $upd->execute([$object, $r['severity'], $priority, $action, $metric, $id]);
    }
    $upserted++;

    if ($r['scale'] >= 4) {
        $selSt->execute([$id]); $c = $selSt->fetch();
        if ($c && $c['state'] === 'new') {
            $esc->execute([$id]);
            if ($esc->rowCount() > 0) {
                $audit->execute([$id, 'swpc', 'new', 'escalated', 'NOAA ' . $r['code'] . ' (' . $r['text'] . ')']);
                $actLog->execute(['swpc', 'swe_escalate', $id . ' ' . $r['code'], 'cron']);
                $escalated++;
                st_notify($cfg, "Clima espacial severo\n" . $object . "\n" . $r['impact']);
            }
        }
    }
}

echo json_encode(['ok' => true, 'source' => 'swpc', 'date' => $date, 'upserted' => $upserted, 'escalated' => $escalated]);
