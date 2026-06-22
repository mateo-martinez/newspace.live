<?php
/**
 * neo.php  -  Proxy con cache para datos espaciales (CORS + TLS resueltos del lado servidor).
 * Sirve, desde tu propio dominio, datos de:
 *   - JPL/CNEOS: cad (aproximaciones), sentry (riesgo), fireball (bolides)   [JSON]
 *   - NASA NeoWs: neows (feed de NEO)                                         [JSON]
 *   - ESA NEOCC: esa_risk (lista de riesgo)                                   [texto]
 *   - CelesTrak: tle (TLEs por grupo, para el visor SSA)                      [texto]
 *
 * Uso:
 *   neo.php?src=cad&date-min=2025-01-01&date-max=2025-03-01&dist-max=30LD&sort=date&fullname=true
 *   neo.php?src=sentry
 *   neo.php?src=fireball&limit=200&sort=-date&date-min=2024-06-01
 *   neo.php?src=neows&start_date=2025-01-01&end_date=2025-01-07
 *   neo.php?src=esa_risk
 *   neo.php?src=tle&GROUP=visual&FORMAT=tle
 *
 * Subir a la raiz de tu dominio y crear al lado una carpeta 'neo_cache' (755).
 */

declare(strict_types=1);

// ===================== CONFIGURACION =====================

// Origenes permitidos para CORS. Para produccion limitá a tu dominio.
$ALLOWED_ORIGINS = ['https://newspace.live'];
// Para pruebas locales podés usar ['*'].

$CACHE_DIR = __DIR__ . '/neo_cache';

$CACHE_MIN = [
    'cad' => 180, 'sentry' => 720, 'fireball' => 360, 'neows' => 120,
    'esa_risk' => 720, 'tle' => 180,
];

// Clave de NeoWs (api.nasa.gov). Queda en el servidor, no se expone al cliente.
$NEOWS_KEY = 'uyg14nwC1dx8FO09gKYkoXhc5NKMxAe6SO3NcblB';

// Bundle de certificados CA. Si ninguno existe, subí https://curl.se/ca/cacert.pem junto a este archivo.
$CA_CANDIDATES = [
    __DIR__ . '/cacert.pem',
    '/etc/pki/tls/certs/ca-bundle.crt',
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
    '/etc/ssl/cert.pem',
];

// Allowlist de endpoints (evita proxy abierto). fmt: json|text ; params permitidos ; key: inyecta api_key.
$ENDPOINTS = [
    'cad'      => ['url' => 'https://ssd-api.jpl.nasa.gov/cad.api',      'fmt' => 'json', 'params' => ['date-min', 'date-max', 'dist-min', 'dist-max', 'sort', 'fullname', 'body', 'limit']],
    'sentry'   => ['url' => 'https://ssd-api.jpl.nasa.gov/sentry.api',   'fmt' => 'json', 'params' => ['des', 'spk', 'h-max', 'ps-min', 'ip-min', 'days', 'all']],
    'fireball' => ['url' => 'https://ssd-api.jpl.nasa.gov/fireball.api', 'fmt' => 'json', 'params' => ['date-min', 'date-max', 'limit', 'sort', 'energy-min', 'req-loc']],
    'neows'    => ['url' => 'https://api.nasa.gov/neo/rest/v1/feed',     'fmt' => 'json', 'params' => ['start_date', 'end_date'], 'key' => true],
    'esa_risk' => ['url' => 'https://neo.ssa.esa.int/PSDB-portlet/download?file=esa_risk_list', 'fmt' => 'text', 'params' => []],
    'tle'      => ['url' => 'https://celestrak.org/NORAD/elements/gp.php', 'fmt' => 'text', 'params' => ['GROUP', 'CATNR', 'NAME', 'INTDES', 'FORMAT']],
];

// NOTA: config.php (secretos) NO se incluye acá. Solo lo carga la rama de alertas (src=alert),
// para que un error de sintaxis en config.php nunca tumbe el proxy de datos ni el visor.

// ===================== CORS =====================

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array('*', $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: *');
} elseif ($origin && in_array($origin, $ALLOWED_ORIGINS, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Expose-Headers: X-Cache, X-Cache-Age');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ===================== VALIDACION =====================

function fail(int $code, string $msg): void
{
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($code);
    echo json_encode(['error' => $msg]);
    exit;
}
function ok_json(array $data): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$src    = $_GET['src'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// ===================== RELAY DE ALERTAS (Act / OODA) =====================
// POST neo.php?src=alert  con cuerpo JSON {system, station, alerts:[{severity,kind,object,message,escalation}]}
// Reenvía a Telegram y/o correo. Los destinatarios y tokens viven en config.php (lado servidor).
if ($src === 'alert') {
    if ($method !== 'POST') {
        fail(405, 'El relay de alertas requiere POST.');
    }
    // Carga de secretos solo acá (aislada del proxy).
    $CONFIG = is_readable(__DIR__ . '/config.php') ? (require __DIR__ . '/config.php') : [];
    if (!empty($CONFIG['allowed_origins']) && is_array($CONFIG['allowed_origins'])) {
        $ALLOWED_ORIGINS = $CONFIG['allowed_origins'];
    }
    // solo desde orígenes permitidos (evita uso abierto)
    if (!in_array('*', $ALLOWED_ORIGINS, true) && $origin && !in_array($origin, $ALLOWED_ORIGINS, true)) {
        fail(403, 'Origen no permitido.');
    }
    // rate limit simple por ventana
    $rl = ($CONFIG['alert']['rate_per_min'] ?? 12);
    if (!alert_ratelimit($CACHE_DIR, (int) $rl)) {
        fail(429, 'Demasiadas alertas en el último minuto.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 65536); // tope 64 KB
    $in  = json_decode((string) $raw, true);
    if (!is_array($in) || empty($in['alerts']) || !is_array($in['alerts'])) {
        fail(400, 'Cuerpo inválido: se espera {alerts:[...]}.');
    }
    $text = format_alert_text($in);
    $channels = [];
    $errors   = [];
    $configured = 0;

    $tg = $CONFIG['telegram'] ?? null;
    if (!empty($tg['token']) && !empty($tg['chat_id'])) {
        $configured++;
        $u = 'https://api.telegram.org/bot' . $tg['token'] . '/sendMessage';
        [$c, $b, $e] = http_post_form($u, ['chat_id' => $tg['chat_id'], 'text' => $text, 'parse_mode' => 'HTML', 'disable_web_page_preview' => 'true'], $CA_CANDIDATES);
        if ($c >= 200 && $c < 300) {
            $channels[] = 'telegram';
        } else {
            $desc = '';
            $j = json_decode((string) $b, true);
            if (is_array($j) && !empty($j['description'])) {
                $desc = $j['description'];
            }
            $errors[] = 'telegram: ' . ($desc ?: ($e ?: ('HTTP ' . $c)));
        }
    }

    $em = $CONFIG['email'] ?? null;
    if (!empty($em['to'])) {
        $configured++;
        $subj = '[SSA/NEO] ' . ($in['system'] ?? 'alert') . ' - ' . count($in['alerts']) . ' alerta(s)';
        $headers = 'MIME-Version: 1.0' . "\r\n" . 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
        if (!empty($em['from'])) {
            $headers .= 'From: ' . $em['from'] . "\r\n";
        }
        $plain = preg_replace('/<[^>]+>/', '', $text);
        if (@mail($em['to'], $subj, $plain, $headers)) {
            $channels[] = 'email';
        } else {
            $errors[] = 'email: mail() falló';
        }
    }

    if (!$channels) {
        $note = $configured === 0
            ? 'No hay canal configurado en config.php (telegram/email). Payload recibido OK.'
            : 'Canal(es) configurado(s) pero el envío falló. Ver "errors".';
        ok_json(['ok' => false, 'channels' => [], 'note' => $note, 'errors' => $errors, 'received' => count($in['alerts'])]);
    }
    ok_json(['ok' => true, 'channels' => $channels, 'errors' => $errors, 'sent' => count($in['alerts'])]);
}

function alert_ratelimit(string $dir, int $perMin): bool
{
    if ($perMin <= 0) {
        return true;
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    $f   = $dir . '/alert_rl.json';
    $now = time();
    $win = [];
    if (is_readable($f)) {
        $win = json_decode((string) file_get_contents($f), true) ?: [];
    }
    $win = array_values(array_filter($win, function ($ts) use ($now) { return ($now - (int) $ts) < 60; }));
    if (count($win) >= $perMin) {
        return false;
    }
    $win[] = $now;
    @file_put_contents($f, json_encode($win), LOCK_EX);
    return true;
}

function format_alert_text(array $in): string
{
    $sys = htmlspecialchars((string) ($in['system'] ?? 'SSA/NEO'), ENT_QUOTES);
    $stn = htmlspecialchars((string) ($in['station'] ?? ($in['country'] ?? '')), ENT_QUOTES);
    $ts  = htmlspecialchars((string) ($in['ts'] ?? gmdate('c')), ENT_QUOTES);
    $lines = ["<b>$sys</b> " . ($stn !== '' ? "· $stn" : ''), $ts, ''];
    $icon = ['critical' => '🔴', 'warning' => '🟠', 'info' => '🟡'];
    foreach (array_slice($in['alerts'], 0, 30) as $a) {
        if (!is_array($a)) {
            continue;
        }
        $sev = strtolower((string) ($a['severity'] ?? 'info'));
        $ic  = $icon[$sev] ?? '•';
        $obj = htmlspecialchars((string) ($a['object'] ?? '?'), ENT_QUOTES);
        $msg = htmlspecialchars((string) ($a['message'] ?? ($a['kind'] ?? '')), ENT_QUOTES);
        $esc = htmlspecialchars((string) ($a['escalation'] ?? ''), ENT_QUOTES);
        $lines[] = "$ic <b>$obj</b> — $msg" . ($esc !== '' ? " [$esc]" : '');
    }
    return implode("\n", $lines);
}

$cfgEndpoint = $ENDPOINTS[$src] ?? null;
if ($cfgEndpoint === null) {
    fail(400, 'Fuente invalida. Use src=cad|sentry|fireball|neows|esa_risk|tle (o POST src=alert).');
}
$cfg = $cfgEndpoint;

$params = [];
foreach ($cfg['params'] as $key) {
    if (isset($_GET[$key]) && $_GET[$key] !== '') {
        $params[$key] = preg_replace('/[\x00-\x1F\x7F]/', '', (string) $_GET[$key]);
    }
}
if (!empty($cfg['key'])) {
    $params['api_key'] = $NEOWS_KEY;
}

$url = $cfg['url'];
if ($params) {
    $sep = (strpos($url, '?') !== false) ? '&' : '?';
    $url .= $sep . http_build_query($params);
}
$fmt = $cfg['fmt'];

// ===================== CACHE =====================

if (!is_dir($CACHE_DIR)) {
    @mkdir($CACHE_DIR, 0755, true);
}
// limpieza automatica: ~1 de cada 20 visitas borra cache de mas de 24 h
if (is_dir($CACHE_DIR) && random_int(1, 20) === 1) {
    foreach (glob($CACHE_DIR . '/*.cache') ?: [] as $f) {
        if (is_file($f) && (time() - filemtime($f)) > 86400) {
            @unlink($f);
        }
    }
}
$cacheFile = $CACHE_DIR . '/' . $src . '_' . md5($url) . '.cache';
$ttl       = ($CACHE_MIN[$src] ?? 120) * 60;
$ctype     = $fmt === 'json' ? 'application/json; charset=utf-8' : 'text/plain; charset=utf-8';

if (is_readable($cacheFile) && (time() - filemtime($cacheFile)) < $ttl) {
    header('Content-Type: ' . $ctype);
    header('X-Cache: HIT');
    header('X-Cache-Age: ' . (time() - filemtime($cacheFile)) . 's');
    echo file_get_contents($cacheFile);
    exit;
}

// ===================== FETCH (server-to-server) =====================

function find_ca(array $cands): ?string
{
    foreach ($cands as $p) {
        if ($p && is_readable($p)) {
            return $p;
        }
    }
    return null;
}

function http_post_form(string $url, array $fields, array $caCands): array
{
    $ca = find_ca($caCands);
    $payload = http_build_query($fields);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'NEO-Watch-proxy/1.0',
        ];
        if ($ca) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return [0, '', $err];
        }
        return [$code, (string) $body, ''];
    }
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
    if ($ca) {
        $ssl['cafile'] = $ca;
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 15, 'header' => 'Content-Type: application/x-www-form-urlencoded', 'content' => $payload], 'ssl' => $ssl]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return [0, '', 'sin curl/allow_url_fopen'];
    }
    return [200, (string) $body, ''];
}

function http_get(string $url, array $caCands): array
{
    $ca = find_ca($caCands);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'NEO-Watch-proxy/1.0',
        ];
        if ($ca) {
            $opts[CURLOPT_CAINFO] = $ca;
        }
        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return [0, '', $err];
        }
        return [$code, (string) $body, ''];
    }
    $ssl = ['verify_peer' => true, 'verify_peer_name' => true];
    if ($ca) {
        $ssl['cafile'] = $ca;
    }
    $ctx  = stream_context_create(['http' => ['timeout' => 25], 'ssl' => $ssl]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return [0, '', 'allow_url_fopen/curl no disponibles o sin CA bundle'];
    }
    return [200, $body, ''];
}

[$code, $body, $err] = http_get($url, $CA_CANDIDATES);

$valid = ($code >= 200 && $code < 300 && $body !== '');
if ($valid && $fmt === 'json') {
    json_decode($body);
    $valid = (json_last_error() === JSON_ERROR_NONE);
}

if ($valid) {
    @file_put_contents($cacheFile, $body, LOCK_EX);
    header('Content-Type: ' . $ctype);
    header('X-Cache: MISS');
    echo $body;
    exit;
}

// respaldo: cache vieja si existe
if (is_readable($cacheFile)) {
    header('Content-Type: ' . $ctype);
    header('X-Cache: STALE');
    echo file_get_contents($cacheFile);
    exit;
}

$hint = '';
if ($err && preg_match('/certificate|issuer|SSL/i', $err)) {
    $hint = ' | Falta el bundle de CA: descargá https://curl.se/ca/cacert.pem y subilo junto a neo.php.';
}
fail(502, 'No se pudo obtener datos de la fuente' . ($err ? ': ' . $err : (' (HTTP ' . $code . ')')) . $hint);
