<?php
/**
 * fragmentation.php  -  Tramo 1 (SST): análisis de fragmentación bajo demanda.
 *
 * action=analyze : calcula el escenario de ruptura (modelo NASA) y, opcional, criba el
 *                  catálogo GP por activos en la banda de altitud afectada. Cualquier usuario logueado.
 * action=create  : crea un incidente de fragmentación. Requiere permiso de trabajo + CSRF.
 *
 * HONESTO: modelo de poblaciones de fragmentos y región orbital afectada, no simulación de
 * cada fragmento ni propagación de la nube. Órdenes de magnitud.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib_auth.php';
require_once __DIR__ . '/fragmentation_lib.php';
require_once __DIR__ . '/reentry_lib.php';

app_cors();
$action = $_GET['action'] ?? 'analyze';
require_login();

function frag_params(): array {
    $b = body_json();
    $g = fn($k, $d = null) => $b[$k] ?? ($_GET[$k] ?? $d);
    return [
        'type'    => ($g('type') === 'collision') ? 'collision' : 'explosion',
        'parent'  => trim((string)$g('parent', 'UNKNOWN')),
        'mass'    => (float)$g('mass_kg', 1000),
        'proj'    => (float)$g('proj_mass_kg', 10),
        'vel'     => (float)$g('vel_kms', 10),
        'alt'     => (float)$g('alt_km', 800),
        'incl'    => (float)$g('incl_deg', 0),
        'dv'      => (float)$g('dv_kms', 0.1),
        'screen'  => (int)$g('screen', 0),
    ];
}

function frag_compute(array $p): array {
    $charMass = 0.0; $catastrophic = null;
    if ($p['type'] === 'collision') {
        $catastrophic = sbm_is_catastrophic($p['mass'], $p['proj'], $p['vel']);
        $charMass = sbm_char_mass($p['mass'], $p['proj'], $p['vel']);
    }
    $bins = sbm_bins($p['type'], $charMass);
    $band = fr_altitude_band($p['alt'], $p['dv']);
    $altLo = max(0.0, $p['alt'] - $band);
    $altHi = $p['alt'] + $band;
    return [
        'type' => $p['type'], 'parent' => $p['parent'],
        'catastrophic' => $catastrophic, 'char_mass_kg' => round($charMass, 1),
        'fragments' => ['gt_1cm' => (int)round($bins['gt_1cm']), 'gt_10cm' => (int)round($bins['gt_10cm']), 'gt_1m' => (int)round($bins['gt_1m'])],
        'affected_band_km' => round($band, 1),
        'affected_alt_lo_km' => round($altLo, 1), 'affected_alt_hi_km' => round($altHi, 1),
        'incl_deg' => $p['incl'],
    ];
}

/** Criba opcional del catálogo: activos cuyo rango perigeo-apogeo cruza la banda y con inclinación cercana. */
function frag_screen(array $r, float $altLo, float $altHi, float $inclEvent, float $inclTol): array {
    $cfg = app_cfg();
    $url = (string)($cfg['fragmentation']['url'] ?? ($cfg['reentry']['url'] ?? ''));
    if ($url === '' || !function_exists('curl_init')) return ['screened' => false];
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_USERAGENT => 'newspace.live SSA frag']);
    $resp = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    if ($resp === false || $code >= 400) return ['screened' => false, 'error' => 'GP fetch HTTP ' . $code];
    $rows = json_decode((string)$resp, true);
    if (!is_array($rows)) return ['screened' => false, 'error' => 'bad GP json'];
    $gv = function ($row, array $keys) { foreach ($keys as $k) { if (isset($row[$k]) && $row[$k] !== '') return $row[$k]; } return null; };
    $at = []; $count = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) continue;
        $mm = $gv($row, ['MEAN_MOTION', 'mean_motion']); if ($mm === null) continue;
        $ec = (float)($gv($row, ['ECCENTRICITY', 'eccentricity']) ?? 0);
        $inc = (float)($gv($row, ['INCLINATION', 'inclination']) ?? 0);
        $pe = re_perigee_km((float)$mm, $ec); $ap = re_apogee_km((float)$mm, $ec);
        if ($ap < $altLo || $pe > $altHi) continue;                 // no cruza la banda
        if ($inclTol > 0 && abs($inc - $inclEvent) > $inclTol) continue;
        $count++;
        if (count($at) < 20) $at[] = trim((string)($gv($row, ['OBJECT_NAME', 'object_name']) ?? '?'));
    }
    return ['screened' => true, 'assets_at_risk' => $count, 'sample' => $at, 'scanned' => count($rows)];
}

if ($action === 'analyze') {
    $p = frag_params();
    $res = frag_compute($p);
    if ($p['screen']) {
        $cfg = app_cfg();
        $tol = (float)($cfg['fragmentation']['incl_tol_deg'] ?? 5.0);
        $res['screen'] = frag_screen($res, $res['affected_alt_lo_km'], $res['affected_alt_hi_km'], $p['incl'], $tol);
    }
    json_out(['ok' => true, 'analysis' => $res]);
}

if ($action === 'create') {
    check_csrf();
    $me = current_user();
    if (!can_work($me)) json_err('forbidden: requires operator role or higher', 403);
    $p = frag_params();
    $res = frag_compute($p);
    $n10 = $res['fragments']['gt_10cm'];
    $sev = $n10 >= 1000 ? 'critical' : ($n10 >= 100 ? 'high' : ($n10 >= 10 ? 'elevated' : 'routine'));
    $rank = ['critical' => 0, 'high' => 1, 'elevated' => 2, 'routine' => 3];
    $id = 'ssa:frag:' . preg_replace('/[^A-Za-z0-9]+/', '-', $p['parent']) . '-' . gmdate('YmdHis');
    $object = $p['parent'] . ' fragmentation';
    $metric = ucfirst($res['type']) . ' | >10cm ~' . $n10 . ' | >1m ~' . $res['fragments']['gt_1m']
            . ' | banda alt ' . $res['affected_alt_lo_km'] . '-' . $res['affected_alt_hi_km'] . ' km'
            . ($res['catastrophic'] === true ? ' | catastrofica' : ($res['catastrophic'] === false ? ' | no catastrofica' : ''));
    app_db()->prepare('INSERT INTO app_incident (id,domain,object,kind,severity,priority,recommended_action,state,metric,opened_utc,updated_at) VALUES (?,?,?,?,?,?,?,?,?,NOW(),NOW())')
        ->execute([$id, 'ssa', $object, 'fragmentacion', $sev, $rank[$sev] * 1000, ($sev === 'critical' || $sev === 'high') ? 'escalate' : 'monitor', 'new', $metric]);
    app_db()->prepare('INSERT INTO app_incident_audit (incident_id,ts,actor_id,actor_name,from_state,to_state,note) VALUES (?,NOW(),?,?,?,?,?)')
        ->execute([$id, (int)$me['id'], $me['name'], null, 'new', 'fragmentation model']);
    log_activity((int)$me['id'], $me['name'], 'fragmentation_create', $id . ' >10cm~' . $n10);
    json_out(['ok' => true, 'id' => $id, 'severity' => $sev, 'analysis' => $res]);
}

json_err('unknown action', 404);
