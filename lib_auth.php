<?php
/**
 * lib_auth.php  -  Fase 4: sesión, autenticación, roles (RBAC), CSRF y helpers JSON.
 * Lo incluyen auth.php, users.php e incidents.php. No se accede por web directo.
 * La conexión y los secretos vienen de config.php (nunca llegan al navegador).
 */
declare(strict_types=1);
require_once __DIR__ . '/db.php'; // define ssa_db(array):PDO

/* ---------- config / db perezosos ---------- */
function app_cfg(): array {
    static $c = null;
    if ($c === null) { $p = __DIR__ . '/config.php'; $c = is_file($p) ? (require $p) : []; }
    return $c;
}
function app_db(): PDO {
    static $db = null;
    if ($db === null) { $db = ssa_db(app_cfg()); }
    return $db;
}

/* ---------- JSON ---------- */
function json_out($data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}
function json_err(string $msg, int $code = 400): void { json_out(['ok' => false, 'error' => $msg], $code); }
function body_json(): array { $d = json_decode((string)file_get_contents('php://input'), true); return is_array($d) ? $d : []; }

/* ---------- CORS (mismo origen del sitio) ---------- */
function app_cors(): void {
    $origins = app_cfg()['allowed_origins'] ?? ['https://newspace.live'];
    $o = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($o && in_array($o, $origins, true)) {
        header('Access-Control-Allow-Origin: ' . $o);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
        header('Access-Control-Allow-Headers: Content-Type, X-CSRF');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
    }
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

/* ---------- sesión ---------- */
function auth_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('nslive_sess');
    session_set_cookie_params([
        'lifetime' => 0, 'path' => '/', 'httponly' => true,
        'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/* ---------- usuario actual / RBAC ---------- */
function current_user(): ?array {
    auth_session_start();
    if (empty($_SESSION['uid'])) return null;
    $st = app_db()->prepare('SELECT id,email,name,role,active FROM app_user WHERE id=?');
    $st->execute([$_SESSION['uid']]);
    $u = $st->fetch();
    if (!$u || (int)$u['active'] !== 1) return null;
    return $u;
}
function require_login(): array { $u = current_user(); if (!$u) json_err('not authenticated', 401); return $u; }
function role_rank(string $r): int { return ['viewer' => 1, 'operator' => 2, 'analyst' => 2, 'admin' => 3][$r] ?? 0; }
function require_role(string $min): array {
    $u = require_login();
    if (role_rank($u['role']) < role_rank($min)) json_err('forbidden', 403);
    return $u;
}
function can_work(array $u): bool { return in_array($u['role'], ['operator', 'analyst', 'admin'], true); }

/* ---------- CSRF ---------- */
function csrf_token(): string {
    auth_session_start();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(18));
    return $_SESSION['csrf'];
}
function check_csrf(): void {
    auth_session_start();
    $t = $_SERVER['HTTP_X_CSRF'] ?? ($_POST['csrf'] ?? (body_json()['csrf'] ?? ''));
    if (empty($_SESSION['csrf']) || !is_string($t) || !hash_equals($_SESSION['csrf'], $t)) json_err('bad csrf', 403);
}

/* ---------- rate limit de login ---------- */
function login_blocked(string $email, string $ip): bool {
    $st = app_db()->prepare(
        "SELECT COUNT(*) c FROM app_login_attempt WHERE ok=0 AND ts > (NOW() - INTERVAL 15 MINUTE) AND (email=? OR ip=?)"
    );
    $st->execute([$email, $ip]);
    return (int)($st->fetch()['c'] ?? 0) >= 8;
}
function login_record(?string $email, string $ip, bool $ok): void {
    $st = app_db()->prepare('INSERT INTO app_login_attempt (email, ip, ok, ts) VALUES (?,?,?,NOW())');
    $st->execute([$email, $ip, $ok ? 1 : 0]);
}
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }

/* ---------- ciclo de vida del incidente (igual que incident_engine.js) ---------- */
function lc_transitions(): array {
    return [
        'new' => ['acknowledged', 'escalated', 'resolved'],
        'acknowledged' => ['in_analysis', 'escalated', 'resolved'],
        'in_analysis' => ['escalated', 'tasked', 'resolved'],
        'escalated' => ['tasked', 'resolved'],
        'tasked' => ['resolved'],
        'resolved' => ['closed', 'new'],
        'closed' => [],
    ];
}
function lc_can(string $from, string $to): bool { return in_array($to, lc_transitions()[$from] ?? [], true); }

/* ---------- registro de actividad ---------- */
function log_activity(?int $uid, ?string $uname, string $action, string $detail = ''): void {
    try {
        $st = app_db()->prepare('INSERT INTO app_activity (user_id,user_name,action,detail,ip,ts) VALUES (?,?,?,?,?,NOW())');
        $st->execute([$uid, $uname, substr($action, 0, 40), substr($detail, 0, 255), client_ip()]);
    } catch (Throwable $e) { /* nunca romper la acción por el log */ }
}
