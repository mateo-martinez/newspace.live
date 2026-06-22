<?php
/**
 * cdm_ingest.php  -  Fase 4 (final): ingesta de CDM de Space-Track.
 *
 * Loguea en Space-Track con las credenciales de config.php (space_track),
 * baja los CDM de conjunciones proximas, toma la Pc OFICIAL del 18th SDS,
 * crea o actualiza el incidente en app_incident y auto-escala con aviso por
 * Telegram del lado servidor. Pensado para cron, protegido por token.
 *
 * Cron (4 veces al dia):
 *   0 1,7,13,19 * * * /usr/local/bin/ea-php83 /home/USUARIO/public_html/cdm_ingest.php >/dev/null 2>&1
 * O por HTTP:  curl "https://newspace.live/cdm_ingest.php?token=EL_TOKEN_DE_INGEST"
 *
 * HONESTO: cdm_public trae la Pc ya calculada (autoritativa) pero NO la covarianza
 * completa. No se recalcula la Pc aca; pc_lib.php queda para verificacion cuando
 * tengas covarianza 2D (CDM de operador o feed comercial). MIN_RNG se asume en km;
 * verifica las unidades contra una muestra real de tu cuenta antes de operar.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pc_lib.php';

$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];

function fail(string $msg, int $code = 500): void { http_response_code($code); echo json_encode(['ok' => false, 'error' => $msg]); exit; }

$isCli = (PHP_SAPI === 'cli');
if (!$isCli) {
    $tok = $_GET['token'] ?? '';
    if (!hash_equals((string)($cfg['ingest']['token'] ?? ''), (string)$tok)) fail('forbidden', 403);
}
if (!function_exists('curl_init')) fail('cURL not available on this server');

$st = $cfg['space_track'] ?? [];
$identity = (string)($st['identity'] ?? '');
$password = (string)($st['password'] ?? '');
$class = (($st['query'] ?? 'cdm_public') === 'cdm') ? 'cdm' : 'cdm_public';
if ($identity === '' || $password === '' || $password === 'CAMBIAME') fail('space_track credentials not configured in config.php');

// --- login ---
$jar = tempnam(sys_get_temp_dir(), 'st_');
$ch = curl_init('https://www.space-track.org/ajaxauth/login');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query(['identity' => $identity, 'password' => $password]),
    CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
    CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_SSL_VERIFYPEER => true,
]);
$loginResp = curl_exec($ch);
$loginCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($loginResp === false || $loginCode >= 400) { @unlink($jar); fail('space-track login failed (HTTP ' . $loginCode . ')'); }

// --- consulta de CDM proximos ---
$url = 'https://www.space-track.org/basicspacedata/query/class/' . $class .
       '/TCA/%3Enow/orderby/TCA%20asc/limit/300/format/json';
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_COOKIEFILE => $jar, CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true,
]);
$resp = curl_exec($ch);
$qcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
@unlink($jar);
if ($resp === false || $qcode >= 400) fail('space-track query failed (HTTP ' . $qcode . ')');

$rows = json_decode($resp, true);
if (!is_array($rows)) fail('unexpected response from space-track');

function st_notify(array $cfg, string $text): void {
    $t = $cfg['telegram'] ?? [];
    $token = (string)($t['token'] ?? ''); $chat = (string)($t['chat_id'] ?? '');
    if ($token === '' || $chat === '' || !function_exists('curl_init')) return;
    $ch = curl_init('https://api.telegram.org/bot' . $token . '/sendMessage');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['chat_id' => $chat, 'text' => $text]),
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch); curl_close($ch);
}

$RANK = ['critical' => 0, 'high' => 1, 'elevated' => 2, 'routine' => 3];

try { $db = ssa_db($cfg); }
catch (Throwable $e) { fail('db connection failed (check config.php / pdo_mysql)'); }

$fetched = count($rows); $upserted = 0; $escalated = 0;

$selSt = $db->prepare('SELECT state FROM app_incident WHERE id=?');
$ins   = $db->prepare('INSERT INTO app_incident (id,domain,object,kind,severity,priority,recommended_action,state,metric,tca_utc,pc,opened_utc,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
$upd   = $db->prepare('UPDATE app_incident SET severity=?,priority=?,recommended_action=?,metric=?,tca_utc=?,pc=COALESCE(?,pc),updated_at=NOW() WHERE id=?');
$audit = $db->prepare('INSERT INTO app_incident_audit (incident_id,ts,actor_id,actor_name,from_state,to_state,note) VALUES (?,NOW(),NULL,?,?,?,?)');
$esc   = $db->prepare("UPDATE app_incident SET state='escalated',updated_at=NOW() WHERE id=? AND state='new'");
$actLog = $db->prepare('INSERT INTO app_activity (user_id,user_name,action,detail,ip,ts) VALUES (NULL,?,?,?,?,NOW())');

foreach ($rows as $r) {
    $tcaStr = $r['TCA'] ?? null; if (!$tcaStr) continue;
    $tcaTs = strtotime($tcaStr); if ($tcaTs === false) continue;
    $s1 = trim((string)($r['SAT_1_NAME'] ?? 'SAT1'));
    $s2 = trim((string)($r['SAT_2_NAME'] ?? 'SAT2'));
    $id1 = (string)($r['SAT_1_ID'] ?? '1'); $id2 = (string)($r['SAT_2_ID'] ?? '2');
    $pc  = (isset($r['PC']) && $r['PC'] !== '') ? (float)$r['PC'] : null;
    $miss = (isset($r['MIN_RNG']) && $r['MIN_RNG'] !== '') ? (float)$r['MIN_RNG'] : null; // se asume km

    $sev = $pc !== null ? pc_band($pc) : miss_band($miss);
    if ($sev === 'unknown') $sev = 'routine';
    $action = ($sev === 'critical' || $sev === 'high') ? 'escalate' : ($sev === 'elevated' ? 'monitor' : 'ignore');
    $hoursToTca = max(0, ($tcaTs - time()) / 3600);
    $priority = $RANK[$sev] * 1000 + min(500, $hoursToTca);
    $id = 'ssa:cdm:' . $id1 . '-' . $id2 . '-' . date('Ymd', $tcaTs);
    $object = $s1 . ' x ' . $s2;
    $metric = 'miss ' . ($miss !== null ? rtrim(rtrim(number_format($miss, 3, '.', ''), '0'), '.') . ' km' : 'n/a')
            . ' | Pc ' . ($pc !== null ? sprintf('%.2e', $pc) : 'n/a') . ' (CDM ' . $class . ')';
    $tcaSql = date('Y-m-d H:i:s', $tcaTs);

    $selSt->execute([$id]); $existing = $selSt->fetch();
    if (!$existing) {
        $ins->execute([$id, 'ssa', $object, 'conjuncion', $sev, $priority, $action, 'new', $metric, $tcaSql, $pc]);
        $audit->execute([$id, 'space-track', null, 'new', 'CDM ' . $class]);
    } else {
        $upd->execute([$sev, $priority, $action, $metric, $tcaSql, $pc, $id]);
    }
    $upserted++;

    if ($pc !== null && $pc >= 1e-4) {
        $selSt->execute([$id]); $cur = $selSt->fetch();
        if ($cur && $cur['state'] === 'new') {
            $esc->execute([$id]);
            if ($esc->rowCount() > 0) {
                $audit->execute([$id, 'space-track', 'new', 'escalated', 'Pc=' . sprintf('%.2e', $pc) . ' >= 1e-4']);
                $actLog->execute(['space-track', 'cdm_escalate', $id . ' Pc=' . sprintf('%.2e', $pc), 'cron']);
                $escalated++;
                st_notify($cfg, "SSA escalado automatico\n" . $object . "\nPc " . sprintf('%.2e', $pc)
                    . ($miss !== null ? "  miss " . number_format($miss, 2) . " km" : "")
                    . "\nTCA " . gmdate('Y-m-d H:i', $tcaTs) . "Z");
            }
        }
    }
}

echo json_encode(['ok' => true, 'class' => $class, 'fetched' => $fetched, 'upserted' => $upserted, 'escalated' => $escalated]);
