<?php
/**
 * tle.php  -  API de lectura de la historia de TLE (para el visor SSA).
 *   tle.php?op=history&norad=25544&limit=40
 *   tle.php?op=maneuvers&hours=168&limit=100
 *   tle.php?op=stats
 */
declare(strict_types=1);

$CONFIG = is_readable(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
require __DIR__ . '/db.php';

$allowed = $CONFIG['allowed_origins'] ?? ['https://newspace.live'];
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', $allowed, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin && in_array($origin, $allowed, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function out(array $d): void
{
    echo json_encode($d);
    exit;
}

try {
    $pdo = ssa_db($CONFIG);
} catch (Throwable $e) {
    http_response_code(500);
    out(['error' => 'BD no disponible. ¿Configuraste config.php e importaste schema.sql?']);
}

$op = $_GET['op'] ?? 'stats';

if ($op === 'history') {
    $norad = (int) ($_GET['norad'] ?? 0);
    $limit = max(1, min(200, (int) ($_GET['limit'] ?? 40)));
    if ($norad <= 0) {
        http_response_code(400);
        out(['error' => 'norad requerido']);
    }
    $st = $pdo->prepare('SELECT norad,name,epoch,mean_motion,inclination,raan,ecc,bstar,ndot,rev,dt_days,d_inc,d_mm,maneuver,maneuver_score,reason,fetched_at
                         FROM tle_history WHERE norad=:n ORDER BY epoch DESC LIMIT ' . $limit);
    $st->execute([':n' => $norad]);
    out(['norad' => $norad, 'rows' => $st->fetchAll()]);
}

if ($op === 'maneuvers') {
    $hours = max(1, min(8760, (int) ($_GET['hours'] ?? 168)));
    $limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));
    $st = $pdo->prepare('SELECT norad,name,epoch,mean_motion,inclination,raan,dt_days,d_inc,d_mm,maneuver_score,reason,fetched_at
                         FROM tle_history WHERE maneuver=1 AND fetched_at >= (UTC_TIMESTAMP() - INTERVAL :h HOUR)
                         ORDER BY fetched_at DESC LIMIT ' . $limit);
    $st->bindValue(':h', $hours, PDO::PARAM_INT);
    $st->execute();
    out(['hours' => $hours, 'rows' => $st->fetchAll()]);
}

// stats por defecto
$objs = (int) $pdo->query('SELECT COUNT(DISTINCT norad) FROM tle_history')->fetchColumn();
$rows = (int) $pdo->query('SELECT COUNT(*) FROM tle_history')->fetchColumn();
$last = $pdo->query('SELECT started_at,finished_at,fetched,inserted,maneuvers,status FROM ingest_run ORDER BY id DESC LIMIT 1')->fetch() ?: null;
out(['objects' => $objs, 'rows' => $rows, 'last_run' => $last]);
