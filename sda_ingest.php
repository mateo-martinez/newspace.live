<?php
/**
 * sda_ingest.php  -  Tramo 1 (SDA): caracterización de maniobras desde la historia de TLE.
 *
 * Lee el último delta de cada objeto en tle_history, descuenta el arrastre (ndot) del cambio
 * de movimiento medio, caracteriza la maniobra (delta-v, tipo, cambio de plano) con sda_lib,
 * y crea o actualiza un incidente (dominio ssa, tipo maniobra) cuando el indicio es relevante.
 *
 * Cron sugerido (después de la ingesta de TLE; horas explícitas):
 *   30 1,7,13,19 * * * /usr/local/bin/ea-php83 /home/USUARIO/public_html/sda_ingest.php >/dev/null 2>&1
 * Prueba en seco:
 *   curl "https://newspace.live/sda_ingest.php?token=TU_TOKEN&dry=1"
 *
 * HONESTO: lo inferido de elementos públicos es INDICIO, no certeza. Un cambio puede ser
 * maniobra, error del elemento o inicio de reentrada. No se afirma intención automáticamente.
 */
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sda_lib.php';

$cfg = is_file(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
function fail(string $m, int $c = 500): void { http_response_code($c); echo json_encode(['ok' => false, 'error' => $m]); exit; }

$isCli = (PHP_SAPI === 'cli');
$dry = $isCli ? in_array('dry', $argv ?? [], true) : isset($_GET['dry']);
if (!$isCli) { if (!hash_equals((string)($cfg['ingest']['token'] ?? ''), (string)($_GET['token'] ?? ''))) fail('forbidden', 403); }

$sda = $cfg['sda'] ?? [];
$minDv = (float)($sda['min_dv_ms'] ?? 5.0) / 1000.0;   // m/s -> km/s
$minDi = (float)($sda['min_di_deg'] ?? 0.03);

try { $db = ssa_db($cfg); } catch (Throwable $e) { fail('db connection failed (check config.php / pdo_mysql)'); }

// último registro por objeto que tenga delta calculado
$sql = "SELECT t.norad,t.name,t.epoch,t.mean_motion,t.inclination,t.ndot,t.dt_days,t.d_inc,t.d_mm
        FROM tle_history t
        JOIN (SELECT norad,MAX(epoch) me FROM tle_history GROUP BY norad) x
          ON t.norad=x.norad AND t.epoch=x.me
        WHERE t.dt_days IS NOT NULL";
try { $rows = $db->query($sql)->fetchAll(); } catch (Throwable $e) { fail('query failed (is tle_history populated?)'); }

$out = []; $skipped = 0;
foreach ($rows as $r) {
    $n = (float)$r['mean_motion'];
    $dt = (float)$r['dt_days'];
    $dmm = $r['d_mm'] !== null ? (float)$r['d_mm'] : 0.0;
    $dinc = $r['d_inc'] !== null ? abs((float)$r['d_inc']) : 0.0;
    $ndot = (float)$r['ndot'];
    $dragDn = 2.0 * $ndot * $dt;             // Δn esperado por arrastre (ndot = n-dot/2)
    $dnResid = $dmm - $dragDn;               // residual tras descontar arrastre
    $c = sda_classify($n, $dnResid, $dinc, $minDv, $minDi);
    if (!$c['maneuver'] || $c['severity'] === 'routine') { $skipped++; continue; }
    $geo = sda_is_geo($n, (float)$r['inclination']);
    $out[] = [
        'norad' => (int)$r['norad'], 'name' => trim((string)($r['name'] ?? ('NORAD ' . $r['norad']))),
        'epoch' => (string)$r['epoch'], 'dt' => round($dt, 2),
        'dv_ms' => $c['dv_ms'], 'type' => $c['type'], 'da_km' => $c['da_km'], 'di_deg' => $c['di_deg'],
        'severity' => $c['severity'], 'geo' => $geo, 'drift' => $geo ? round(sda_geo_drift($n), 3) : null,
    ];
}

if ($dry) {
    usort($out, fn($a, $b) => $b['dv_ms'] <=> $a['dv_ms']);
    echo json_encode(['ok' => true, 'dry_run' => true, 'objects' => count($rows), 'maneuvers' => count($out), 'skipped' => $skipped, 'sample' => array_slice($out, 0, 20)], JSON_PRETTY_PRINT);
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
$TYPE = ['raise' => 'subida', 'lower' => 'bajada', 'plane' => 'cambio de plano', 'combined' => 'combinada', 'none' => '-'];
$upserted = 0; $escalated = 0;
$selSt  = $db->prepare('SELECT state FROM app_incident WHERE id=?');
$ins    = $db->prepare('INSERT INTO app_incident (id,domain,object,kind,severity,priority,recommended_action,state,metric,tca_utc,opened_utc,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
$upd    = $db->prepare('UPDATE app_incident SET object=?,severity=?,priority=?,recommended_action=?,metric=?,tca_utc=?,updated_at=NOW() WHERE id=?');
$audit  = $db->prepare('INSERT INTO app_incident_audit (incident_id,ts,actor_id,actor_name,from_state,to_state,note) VALUES (?,NOW(),NULL,?,?,?,?)');
$esc    = $db->prepare("UPDATE app_incident SET state='escalated',updated_at=NOW() WHERE id=? AND state='new'");
$actLog = $db->prepare('INSERT INTO app_activity (user_id,user_name,action,detail,ip,ts) VALUES (NULL,?,?,?,?,NOW())');

foreach ($out as $o) {
    $action = ($o['severity'] === 'high') ? 'escalate' : 'monitor';
    $priority = $RANK[$o['severity']] * 1000;
    $id = 'ssa:sda:' . $o['norad'];
    $metric = 'maniobra ' . ($TYPE[$o['type']] ?? $o['type']) . ' | dv ~' . $o['dv_ms'] . ' m/s'
            . ($o['da_km'] != 0 ? ' | da ' . $o['da_km'] . ' km' : '')
            . ($o['di_deg'] >= 0.01 ? ' | di ' . $o['di_deg'] . ' deg' : '')
            . ' | dt ' . $o['dt'] . ' d'
            . ($o['geo'] ? ' | deriva GEO ' . $o['drift'] . ' deg/d' : '') . ' (indicio)';
    $tcaSql = gmdate('Y-m-d H:i:s', strtotime($o['epoch'] . ' UTC') ?: time());

    $selSt->execute([$id]); $exists = $selSt->fetch();
    if (!$exists) {
        $ins->execute([$id, 'ssa', $o['name'], 'maniobra', $o['severity'], $priority, $action, 'new', $metric, $tcaSql]);
        $audit->execute([$id, 'sda', null, 'new', 'dv ~' . $o['dv_ms'] . ' m/s ' . $o['type']]);
    } else {
        $upd->execute([$o['name'], $o['severity'], $priority, $action, $metric, $tcaSql, $id]);
    }
    $upserted++;

    if ($o['severity'] === 'high') {
        $selSt->execute([$id]); $cur = $selSt->fetch();
        if ($cur && $cur['state'] === 'new') {
            $esc->execute([$id]);
            if ($esc->rowCount() > 0) {
                $audit->execute([$id, 'sda', 'new', 'escalated', 'dv ~' . $o['dv_ms'] . ' m/s ' . $o['type']]);
                $actLog->execute(['sda', 'sda_maneuver', $id . ' dv=' . $o['dv_ms'] . ' ' . $o['type'], 'cron']);
                $escalated++;
                st_notify($cfg, "Indicio de maniobra\n" . $o['name'] . "\n" . ($TYPE[$o['type']] ?? $o['type']) . ", dv ~" . $o['dv_ms'] . " m/s");
            }
        }
    }
}

echo json_encode(['ok' => true, 'source' => 'sda', 'objects' => count($rows), 'maneuvers' => $upserted, 'escalated' => $escalated, 'skipped' => $skipped]);
