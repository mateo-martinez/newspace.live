<?php
/**
 * ingest.php  -  Worker de ingesta de TLE (correr por cron).
 *
 * Descarga TLEs de CelesTrak, los versiona en tle_history (una fila por época nueva
 * de cada objeto) y marca posibles maniobras comparando con el TLE anterior.
 *
 * Ejecución:
 *   - CLI (recomendado):   php /home/USUARIO/public_html/ingest.php
 *   - HTTP (cron+curl):    curl "https://newspace.live/ingest.php?token=TU_TOKEN"
 *
 * Cron sugerido en cPanel (cada 6 h, sin usar la sintaxis *-barra que rompe este comentario):
 *   0 0,6,12,18 * * *  /usr/local/bin/php /home/USUARIO/public_html/ingest.php >/dev/null 2>&1
 */
declare(strict_types=1);
@set_time_limit(280);
error_reporting(E_ALL);
ini_set('display_errors', '0');

$IS_CLI = (PHP_SAPI === 'cli');

// Salida JSON de error legible (en vez de un 500 en blanco).
function ingest_fail($msg, int $code = 500): void
{
    global $IS_CLI;
    if ($IS_CLI) {
        fwrite(STDERR, "ERROR: $msg\n");
    } else {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => $msg]);
    }
    exit;
}
// Captura errores fatales / parse de archivos incluidos (config.php, db.php) y los muestra.
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_COMPILE_ERROR, E_CORE_ERROR], true)) {
        ingest_fail('Error fatal: ' . $e['message'] . ' en ' . basename((string) $e['file']) . ':' . $e['line']);
    }
});
set_exception_handler(function ($ex) {
    ingest_fail('Excepción: ' . $ex->getMessage());
});

// Comprobaciones previas con mensajes claros.
if (!extension_loaded('pdo_mysql')) {
    ingest_fail('Falta la extensión pdo_mysql. Activala en cPanel -> Select PHP Version -> Extensions.');
}
if (!is_readable(__DIR__ . '/config.php')) {
    ingest_fail('No existe config.php. Copiá config.sample.php a config.php y completá la base de datos y el token.');
}
if (!is_readable(__DIR__ . '/db.php')) {
    ingest_fail('Falta db.php junto a ingest.php.');
}

$CONFIG = require __DIR__ . '/config.php';   // si tiene typo, el shutdown handler reporta el parse error
if (!is_array($CONFIG)) {
    ingest_fail('config.php no devuelve un array. ¿Olvidaste el "return [ ... ];"?');
}
require __DIR__ . '/db.php';

if (!$IS_CLI) {
    header('Content-Type: application/json; charset=utf-8');
    $tok = $_GET['token'] ?? '';
    $exp = $CONFIG['ingest']['token'] ?? '';
    if ($exp === '' || !hash_equals((string) $exp, (string) $tok)) {
        ingest_fail('Token inválido. Usá ingest.php?token=... con el token de config.php (ingest.token).', 403);
    }
}

$CA_CANDIDATES = [
    __DIR__ . '/cacert.pem',
    '/etc/pki/tls/certs/ca-bundle.crt',
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
    '/etc/ssl/cert.pem',
];
function find_ca(array $cands): ?string
{
    foreach ($cands as $p) {
        if ($p && is_readable($p)) {
            return $p;
        }
    }
    return null;
}
function fetch_text(string $url, array $caCands): array
{
    $ca = find_ca($caCands);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'SSA-ingest/1.0',
        ];
        if ($ca) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return [$code, $body === false ? '' : (string) $body, $err];
    }
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
    if ($ca) {
        $ssl['cafile'] = $ca;
    }
    $ctx  = stream_context_create(['http' => ['timeout' => 60], 'ssl' => $ssl]);
    $body = @file_get_contents($url, false, $ctx);
    return [$body === false ? 0 : 200, $body === false ? '' : $body, ''];
}

/* --------- parsing de TLE --------- */
function tle_exp(string $s): float
{
    $s = trim($s);
    if ($s === '' || preg_match('/^[+\- ]?0+[+\-]?0?$/', $s)) {
        return 0.0;
    }
    $sign = 1.0;
    if ($s[0] === '-') { $sign = -1.0; $s = substr($s, 1); }
    elseif ($s[0] === '+') { $s = substr($s, 1); }
    $exp  = (int) substr($s, -2);
    $mant = substr($s, 0, strlen($s) - 2);
    if ($mant === '') {
        return 0.0;
    }
    return $sign * ((float) ('0.' . $mant)) * pow(10, $exp);
}
function tle_epoch(string $l1): ?string
{
    $yy = (int) substr($l1, 18, 2);
    $dd = (float) substr($l1, 20, 12);
    if ($dd <= 0) {
        return null;
    }
    $year = $yy < 57 ? 2000 + $yy : 1900 + $yy;
    $secs = ($dd - 1.0) * 86400.0;
    $base = strtotime($year . '-01-01 00:00:00 UTC');
    if ($base === false) {
        return null;
    }
    return gmdate('Y-m-d H:i:s', $base + (int) round($secs));
}
function parse_tle_block(string $text): array
{
    $lines = preg_split('/\r?\n/', $text);
    $out = [];
    $i = 0;
    $n = count($lines);
    while ($i < $n) {
        $ln = rtrim($lines[$i] ?? '');
        if (isset($lines[$i + 1], $lines[$i + 2]) && strpos($lines[$i + 1], '1 ') === 0 && strpos($lines[$i + 2], '2 ') === 0) {
            $name = trim($ln);
            $l1 = $lines[$i + 1];
            $l2 = $lines[$i + 2];
            $i += 3;
        } elseif (strpos($ln, '1 ') === 0 && isset($lines[$i + 1]) && strpos($lines[$i + 1], '2 ') === 0) {
            $name = null;
            $l1 = $ln;
            $l2 = $lines[$i + 1];
            $i += 2;
        } else {
            $i += 1;
            continue;
        }
        if (strlen($l1) < 63 || strlen($l2) < 63) {
            continue;
        }
        $epoch = tle_epoch($l1);
        if ($epoch === null) {
            continue;
        }
        $out[] = [
            'norad'        => (int) trim(substr($l1, 2, 5)),
            'name'         => $name !== null ? substr($name, 0, 64) : null,
            'epoch'        => $epoch,
            'epoch_ts'     => strtotime($epoch . ' UTC'),
            'ndot'         => (float) str_replace(' ', '', substr($l1, 33, 10)),
            'bstar'        => tle_exp(substr($l1, 53, 8)),
            'inclination'  => (float) substr($l2, 8, 8),
            'raan'         => (float) substr($l2, 17, 8),
            'ecc'          => (float) ('0.' . trim(substr($l2, 26, 7))),
            'argp'         => (float) substr($l2, 34, 8),
            'mean_anomaly' => (float) substr($l2, 43, 8),
            'mean_motion'  => (float) substr($l2, 52, 11),
            'rev'          => (int) substr($l2, 63, 5),
            'line1'        => rtrim($l1),
            'line2'        => rtrim($l2),
        ];
    }
    return $out;
}

/* --------- detector heurístico de maniobras --------- */
function maneuver_eval(array $cur, ?array $prev, array $cfg): array
{
    if (!$prev) {
        return [0, 0.0, null, null, null, null];
    }
    $dt   = max(1e-6, ($cur['epoch_ts'] - $prev['epoch_ts']) / 86400.0);
    $dinc = abs($cur['inclination'] - $prev['inclination']);
    $dmm  = $cur['mean_motion'] - $prev['mean_motion'];
    $thrI = (float) ($cfg['ingest']['man_dinc_deg'] ?? 0.03);
    $thrR = (float) ($cfg['ingest']['man_dmm_raise'] ?? 1e-4);
    $score = 0.0;
    $why = [];
    if ($dinc > $thrI) {
        $score += min(1.0, $dinc / max($thrI * 3, 0.09));
        $why[] = 'Δi=' . round($dinc, 3) . '°';
    }
    if ($dmm < -$thrR) {            // mov. medio baja => órbita elevada => propulsivo
        $score += min(1.0, abs($dmm) / max($thrR * 5, 5e-4));
        $why[] = 'mm↓ ' . sprintf('%+.2e', $dmm);
    }
    $rate = abs($dmm) / $dt;
    if ($rate > 5e-4) {
        $score += min(0.6, $rate / 2e-3);
        $why[] = 'Δmm/dt alto';
    }
    $score = round(min(1.0, $score), 3);
    $man = $score >= 0.5 ? 1 : 0;
    return [$man, $score, round($dt, 4), round($dinc, 5), $dmm, $why ? implode(' ', $why) : null];
}

/* --------- ejecución --------- */
$pdo = ssa_db($CONFIG);
$groups = $CONFIG['ingest']['groups'] ?? ['visual'];
$cap    = (int) ($CONFIG['ingest']['max_objects'] ?? 0);

$pdo->prepare('INSERT INTO ingest_run (started_at, groups, status) VALUES (UTC_TIMESTAMP(), :g, :s)')
    ->execute([':g' => implode(',', $groups), ':s' => 'running']);
$runId = (int) $pdo->lastInsertId();

$fetched = 0; $inserted = 0; $maneuvers = 0; $errs = [];

// cabeza de la cadena de hash (global)
$head = $pdo->query('SELECT hash FROM tle_history ORDER BY id DESC LIMIT 1')->fetchColumn();
$prevHash = $head !== false ? (string) $head : str_repeat('0', 64);

$selLast = $pdo->prepare('SELECT epoch, mean_motion, inclination, raan FROM tle_history WHERE norad = :n ORDER BY epoch DESC LIMIT 1');
$ins = $pdo->prepare(
    'INSERT IGNORE INTO tle_history
     (norad,name,epoch,mean_motion,inclination,raan,ecc,argp,mean_anomaly,bstar,ndot,rev,line1,line2,
      dt_days,d_inc,d_mm,maneuver,maneuver_score,reason,source,fetched_at,prev_hash,hash)
     VALUES (:norad,:name,:epoch,:mm,:inc,:raan,:ecc,:argp,:ma,:bstar,:ndot,:rev,:l1,:l2,
      :dt,:dinc,:dmm,:man,:msc,:reason,:source,UTC_TIMESTAMP(),:ph,:h)'
);

foreach ($groups as $g) {
    $url = 'https://celestrak.org/NORAD/elements/gp.php?GROUP=' . rawurlencode($g) . '&FORMAT=tle';
    [$code, $body, $err] = fetch_text($url, $CA_CANDIDATES);
    if ($code < 200 || $code >= 300 || $body === '') {
        $errs[] = "grupo $g: " . ($err ?: ('HTTP ' . $code));
        continue;
    }
    $objs = parse_tle_block($body);
    if ($cap > 0) {
        $objs = array_slice($objs, 0, $cap);
    }
    foreach ($objs as $o) {
        $fetched++;
        $selLast->execute([':n' => $o['norad']]);
        $last = $selLast->fetch();
        if ($last && strtotime($last['epoch'] . ' UTC') >= $o['epoch_ts']) {
            continue; // sin época nueva
        }
        $prev = $last ? [
            'epoch_ts'    => strtotime($last['epoch'] . ' UTC'),
            'mean_motion' => (float) $last['mean_motion'],
            'inclination' => (float) $last['inclination'],
            'raan'        => (float) $last['raan'],
        ] : null;
        [$man, $msc, $dt, $dinc, $dmm, $reason] = maneuver_eval($o, $prev, $CONFIG);

        $rowMaterial = $prevHash . '|' . $o['norad'] . '|' . $o['epoch'] . '|' . $o['line1'] . '|' . $o['line2'];
        $h = hash('sha256', $rowMaterial);

        try {
            $ins->execute([
                ':norad' => $o['norad'], ':name' => $o['name'], ':epoch' => $o['epoch'],
                ':mm' => $o['mean_motion'], ':inc' => $o['inclination'], ':raan' => $o['raan'],
                ':ecc' => $o['ecc'], ':argp' => $o['argp'], ':ma' => $o['mean_anomaly'],
                ':bstar' => $o['bstar'], ':ndot' => $o['ndot'], ':rev' => $o['rev'],
                ':l1' => $o['line1'], ':l2' => $o['line2'],
                ':dt' => $dt, ':dinc' => $dinc, ':dmm' => $dmm,
                ':man' => $man, ':msc' => $msc, ':reason' => $reason,
                ':source' => 'celestrak:' . $g, ':ph' => $prevHash, ':h' => $h,
            ]);
            if ($ins->rowCount() > 0) {
                $inserted++;
                $prevHash = $h;             // avanza la cadena solo si insertó
                if ($man) {
                    $maneuvers++;
                }
            }
        } catch (Throwable $e) {
            $errs[] = 'norad ' . $o['norad'] . ': ' . $e->getMessage();
        }
    }
}

$pdo->prepare('UPDATE ingest_run SET finished_at=UTC_TIMESTAMP(), fetched=:f, inserted=:i, maneuvers=:m, status=:s, note=:n WHERE id=:id')
    ->execute([
        ':f' => $fetched, ':i' => $inserted, ':m' => $maneuvers,
        ':s' => $errs ? 'partial' : 'ok',
        ':n' => $errs ? substr(implode(" | ", $errs), 0, 255) : null,
        ':id' => $runId,
    ]);

$summary = ['run' => $runId, 'groups' => $groups, 'fetched' => $fetched, 'inserted' => $inserted, 'maneuvers' => $maneuvers, 'errors' => $errs];
if ($IS_CLI) {
    fwrite(STDOUT, json_encode($summary, JSON_PRETTY_PRINT) . "\n");
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($summary);
}
