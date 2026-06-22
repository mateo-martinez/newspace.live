<?php
/**
 * users.php  -  Fase 4: gestión de usuarios. Todas las acciones requieren rol admin.
 *   list                            -> lista de usuarios
 *   create  POST {email,name,role,password}
 *   update  POST {id,role?,name?,active?}
 *   resetpw POST {id,password}
 * Un admin no puede desactivarse ni bajarse de rol a sí mismo (evita quedarse sin admin).
 */
declare(strict_types=1);
require_once __DIR__ . '/lib_auth.php';
app_cors();

$ROLES = ['admin', 'analyst', 'operator', 'viewer'];
$action = $_GET['action'] ?? '';

function urow(array $u): array {
    return [
        'id' => (int)$u['id'], 'email' => $u['email'], 'name' => $u['name'],
        'role' => $u['role'], 'active' => (int)$u['active'],
        'created_at' => $u['created_at'], 'last_login' => $u['last_login'],
    ];
}

try {
    $me = require_role('admin');

    if ($action === 'list') {
        $rows = app_db()->query('SELECT id,email,name,role,active,created_at,last_login FROM app_user ORDER BY created_at')->fetchAll();
        json_out(['ok' => true, 'users' => array_map('urow', $rows)]);
    }

    if ($action === 'create') {
        check_csrf();
        $b = body_json();
        $email = trim((string)($b['email'] ?? ''));
        $name = trim((string)($b['name'] ?? ''));
        $role = (string)($b['role'] ?? 'viewer');
        $pass = (string)($b['password'] ?? '');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_err('invalid email');
        if ($name === '') json_err('name required');
        if (!in_array($role, $ROLES, true)) json_err('invalid role');
        if (strlen($pass) < 10) json_err('password too short (min 10)');
        try {
            $st = app_db()->prepare('INSERT INTO app_user (email,name,pass_hash,role,active,created_at) VALUES (?,?,?,?,1,NOW())');
            $st->execute([$email, $name, password_hash($pass, PASSWORD_DEFAULT), $role]);
        } catch (PDOException $e) {
            json_err('email already exists', 409);
        }
        $id = (int)app_db()->lastInsertId();
        log_activity((int)$me['id'], $me['name'], 'user_create', $email . ' (' . $role . ')');
        json_out(['ok' => true, 'id' => $id]);
    }

    if ($action === 'update') {
        check_csrf();
        $b = body_json();
        $id = (int)($b['id'] ?? 0);
        if ($id <= 0) json_err('id required');
        $sets = []; $vals = [];
        if (isset($b['role'])) {
            if (!in_array($b['role'], $ROLES, true)) json_err('invalid role');
            if ($id === (int)$me['id'] && $b['role'] !== 'admin') json_err('cannot demote yourself', 409);
            $sets[] = 'role=?'; $vals[] = $b['role'];
        }
        if (isset($b['name']) && trim((string)$b['name']) !== '') { $sets[] = 'name=?'; $vals[] = trim((string)$b['name']); }
        if (isset($b['active'])) {
            $act = $b['active'] ? 1 : 0;
            if ($id === (int)$me['id'] && $act === 0) json_err('cannot deactivate yourself', 409);
            $sets[] = 'active=?'; $vals[] = $act;
        }
        if (!$sets) json_err('nothing to update');
        $vals[] = $id;
        app_db()->prepare('UPDATE app_user SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
        log_activity((int)$me['id'], $me['name'], 'user_update', 'id=' . $id . ' ' . implode(' ', $sets));
        json_out(['ok' => true]);
    }

    if ($action === 'resetpw') {
        check_csrf();
        $b = body_json();
        $id = (int)($b['id'] ?? 0);
        $pass = (string)($b['password'] ?? '');
        if ($id <= 0) json_err('id required');
        if (strlen($pass) < 10) json_err('password too short (min 10)');
        app_db()->prepare('UPDATE app_user SET pass_hash=? WHERE id=?')->execute([password_hash($pass, PASSWORD_DEFAULT), $id]);
        log_activity((int)$me['id'], $me['name'], 'user_resetpw', 'id=' . $id);
        json_out(['ok' => true]);
    }

    if ($action === 'activity') {
        $uid = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;
        if ($uid > 0) {
            $st = app_db()->prepare('SELECT user_id,user_name,action,detail,ip,ts FROM app_activity WHERE user_id=? ORDER BY ts DESC LIMIT 100');
            $st->execute([$uid]);
        } else {
            $st = app_db()->query('SELECT user_id,user_name,action,detail,ip,ts FROM app_activity ORDER BY ts DESC LIMIT 100');
        }
        json_out(['ok' => true, 'activity' => $st->fetchAll()]);
    }

    json_err('unknown action', 404);
} catch (Throwable $e) {
    json_err('server error', 500);
}
