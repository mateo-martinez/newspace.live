<?php
/**
 * auth.php  -  Fase 4: bootstrap del primer admin, login, logout, csrf/me.
 * Acciones (GET ?action= o POST):
 *   csrf      -> { ok, csrf, user|null, needs_bootstrap }
 *   bootstrap -> crea el PRIMER admin SOLO si no hay usuarios. POST {email,name,password}
 *   login     -> POST {email,password}
 *   logout    -> cierra sesión
 */
declare(strict_types=1);
require_once __DIR__ . '/lib_auth.php';
app_cors();

$action = $_GET['action'] ?? '';

function users_count(): int {
    return (int)(app_db()->query('SELECT COUNT(*) c FROM app_user')->fetch()['c'] ?? 0);
}
function user_public(array $u): array {
    return ['id' => (int)$u['id'], 'email' => $u['email'], 'name' => $u['name'], 'role' => $u['role']];
}

try {
    if ($action === 'csrf') {
        $u = current_user();
        json_out([
            'ok' => true,
            'csrf' => csrf_token(),
            'user' => $u ? user_public($u) : null,
            'needs_bootstrap' => users_count() === 0,
        ]);
    }

    if ($action === 'bootstrap') {
        check_csrf();
        if (users_count() > 0) json_err('bootstrap already done', 403);
        $b = body_json();
        $email = trim((string)($b['email'] ?? ''));
        $name = trim((string)($b['name'] ?? ''));
        $pass = (string)($b['password'] ?? '');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('invalid email');
        if ($name === '') json_err('name required');
        if (strlen($pass) < 10) json_err('password too short (min 10)');
        $st = app_db()->prepare('INSERT INTO app_user (email,name,pass_hash,role,active,created_at) VALUES (?,?,?,?,1,NOW())');
        $st->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT), 'admin']);
        $_SESSION['uid'] = (int)app_db()->lastInsertId();
        log_activity((int)$_SESSION['uid'], $name, 'bootstrap_admin', $email);
        json_out(['ok' => true, 'user' => user_public(current_user())]);
    }

    if ($action === 'login') {
        check_csrf();
        $b = body_json();
        $email = trim((string)($b['email'] ?? ''));
        $pass = (string)($b['password'] ?? '');
        $ip = client_ip();
        if (login_blocked($email, $ip)) json_err('too many attempts, wait 15 min', 429);
        $st = app_db()->prepare('SELECT * FROM app_user WHERE email=?');
        $st->execute([$email]);
        $u = $st->fetch();
        if (!$u || (int)$u['active'] !== 1 || !password_verify($pass, $u['pass_hash'])) {
            login_record($email, $ip, false);
            json_err('invalid credentials', 401);
        }
        login_record($email, $ip, true);
        $_SESSION['uid'] = (int)$u['id'];
        app_db()->prepare('UPDATE app_user SET last_login=NOW() WHERE id=?')->execute([(int)$u['id']]);
        log_activity((int)$u['id'], $u['name'], 'login');
        json_out(['ok' => true, 'user' => user_public($u)]);
    }

    if ($action === 'logout') {
        auth_session_start();
        $u = current_user();
        if ($u) log_activity((int)$u['id'], $u['name'], 'logout');
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        json_out(['ok' => true]);
    }

    json_err('unknown action', 404);
} catch (Throwable $e) {
    json_err('server error', 500);
}
