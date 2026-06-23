<?php
/**
 * socrates_ingest.php  -  Tramo 1: ingesta de conjunciones de CelesTrak SOCRATES.
 *
 * Baja el feed de SOCRATES (publico, sin login), normaliza cada conjuncion con
 * socrates_lib.php y crea o actualiza un incidente en app_incident (dominio ssa).
 * La probabilidad es de CRIBADO sobre elementos publicos, no una Pc con covarianza,
 * y asi se rotula. Protegido por el token de ingesta. Pensado para cron.
 *
 * Cron sugerido (3 veces al dia, alineado a la publicacion de SOCRATES):
 *   0 2,10,18 * * * /usr/local/bin/ea-php83 /home/USUARIO/public_html/socrates_ingest.php >/dev/null 2>&1
 *
 * Prueba en seco (no escribe en la base, devuelve lo parseado):
 *   curl "https://newspace.live/socrates_ingest.php?token=TU_TOKEN&dry=1"
 *
 * HONESTO: el formato exacto de CelesTrak puede variar. Corre la prueba en seco
 * contra el feed real y confirma que los campos se mapean bien antes de prender el cron.
 * MIN_RNG se asume en km.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/socrates_lib.php';

$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
function fail(string $m, int $c = 500): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

$isCli = (PHP_SAPI === 'cli');
$dry = $isCli ? in_array('dry', $argv ?? [], true) : isset($_GET['dry']);
if (!$isCli) {
    if (!hash_equals((string)($cfg['ingest']['token'] ?? ''), (string)($_GET['token'] ?? ''))) fail('forbidden', 403);
}
if (!function_exists('curl_init')) fail('cURL not available on this server');

$url = (string)($cfg['socrates']['url'] ?? '');
if ($url === '') fail('set socrates.url in config.php (SOCRATES Plus CSV/JSON endpoint)');

$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'newspace.live SSA ingest']);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
if ($resp === false || $code >= 400) fail('socrates fetch failed (HTTP ' . $code . ')');

$rows = socrates_parse((string)$resp);
if (!$rows) fail('could not parse socrates response (empty or unrecognized format). Run with &dry=1 to inspect.');

// modo prueba en seco: muestra las claves crudas y el mapeo de los primeros registros
if ($dry) {
    $sample = [];
    foreach (array_slice($rows, 0, 5) as $r) {
        if (!is_array($r)) continue;
        $sample[] = ['raw_keys' => array_keys($r), 'mapped' => soc_map($r)];
    }
    echo json_encode(['ok' => true, 'dry_run' => true, 'fetched' => count($rows), 'sample' => $sample], JSON_PRETTY_PRINT);
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

$fetched = count($rows); $upserted = 0; $escalated = 0; $skipped = 0;
$minProb = (float)($cfg['socrates']['min_prob'] ?? 1e-5);
$maxMiss = (float)($cfg['socrates']['max_miss_km'] ?? 1.0);
$selSt  = $db->prepare('SELECT state FROM app_incident WHERE id=?');
$ins    = $db->prepare('INSERT INTO app_incident (id,domain,object,kind,severity,priority,recommended_action,state,metric,tca_utc,pc,opened_utc,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
$upd    = $db->prepare('UPDATE app_incident SET severity=?,priority=?,recommended_action=?,metric=?,tca_utc=?,pc=COALESCE(?,pc),updated_at=NOW() WHERE id=?');
$audit  = $db->prepare('INSERT INTO app_incident_audit (incident_id,ts,actor_id,actor_name,from_state,to_state,note) VALUES (?,NOW(),NULL,?,?,?,?)');
$esc    = $db->prepare("UPDATE app_incident SET state='escalated',updated_at=NOW() WHERE id=? AND state='new'");
$actLog = $db->prepare('INSERT INTO app_activity (user_id,user_name,action,detail,ip,ts) VALUES (NULL,?,?,?,?,NOW())');

foreach ($rows as $r) {
    if (!is_array($r)) continue;
    $m = soc_map($r);
    if ($m === null) continue;
    // filtro de relevancia: solo entran a la cola conjunciones notables (evita inundar)
    $keep = ($m['pc'] !== null && $m['pc'] >= $minProb) || ($m['miss'] !== null && $m['miss'] <= $maxMiss);
    if (!$keep) { $skipped++; continue; }
    $action = ($m['severity'] === 'critical' || $m['severity'] === 'high') ? 'escalate' : ($m['severity'] === 'elevated' ? 'monitor' : 'ignore');
    $hoursToTca = max(0, ($m['tcaTs'] - time()) / 3600);
    $priority = $RANK[$m['severity']] * 1000 + min(500, $hoursToTca);
    $id = 'ssa:soc:' . $m['id1'] . '-' . $m['id2'] . '-' . date('Ymd', $m['tcaTs']);
    $object = $m['name1'] . ' x ' . $m['name2'];
    $metric = 'miss ' . ($m['miss'] !== null ? rtrim(rtrim(number_format($m['miss'], 3, '.', ''), '0'), '.') . ' km' : 'n/a')
            . ' | Pc max ' . ($m['pc'] !== null ? sprintf('%.2e', $m['pc']) : 'n/a') . ' (SOCRATES)'
            . ($m['relVel'] !== null ? ' | ' . number_format($m['relVel'], 1) . ' km/s' : '')
            . ($m['dse'] !== null ? ' | DSE ' . number_format($m['dse'], 1) . ' d' : '');

    $selSt->execute([$id]); $exists = $selSt->fetch();
    if (!$exists) {
        $ins->execute([$id, 'ssa', $object, 'conjuncion', $m['severity'], $priority, $action, 'new', $metric, $m['tcaSql'], $m['pc']]);
        $audit->execute([$id, 'socrates', null, 'new', 'SOCRATES']);
    } else {
        $upd->execute([$m['severity'], $priority, $action, $metric, $m['tcaSql'], $m['pc'], $id]);
    }
    $upserted++;

    if ($m['pc'] !== null && $m['pc'] >= 1e-4) {
        $selSt->execute([$id]); $cur = $selSt->fetch();
        if ($cur && $cur['state'] === 'new') {
            $esc->execute([$id]);
            if ($esc->rowCount() > 0) {
                $audit->execute([$id, 'socrates', 'new', 'escalated', 'Pc max (SOCRATES)=' . sprintf('%.2e', $m['pc']) . ' >= 1e-4']);
                $actLog->execute(['socrates', 'soc_escalate', $id . ' Pcmax=' . sprintf('%.2e', $m['pc']), 'cron']);
                $escalated++;
                st_notify($cfg, "SSA conjuncion (SOCRATES)\n" . $object . "\nPc max " . sprintf('%.2e', $m['pc'])
                    . ($m['miss'] !== null ? "  miss " . number_format($m['miss'], 2) . " km" : "")
                    . "\nTCA " . gmdate('Y-m-d H:i', $m['tcaTs']) . "Z");
            }
        }
    }
}

echo json_encode(['ok' => true, 'source' => 'socrates', 'fetched' => $fetched, 'upserted' => $upserted, 'escalated' => $escalated, 'skipped' => $skipped]);
