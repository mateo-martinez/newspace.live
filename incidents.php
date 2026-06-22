<?php
/**
 * incidents.php  -  Fase 4: incidentes compartidos en la base, con RBAC y auditoría.
 *   list   GET  ?domain=ssa|neo&state=active|all|resolved   (cualquier usuario logueado)
 *   get    GET  ?id=...
 *   upsert POST {id,domain,object,...}     (operator+; alta/actualización desde el motor del front)
 *   transition POST {id,to,note}           (operator+; valida ciclo de vida y audita)
 * El front sigue calculando severidad/prioridad/acción con incident_engine.js;
 * acá se persiste, se comparte entre usuarios, se controla acceso y se audita.
 */
declare(strict_types=1);
require_once __DIR__ . '/lib_auth.php';
app_cors();

$action = $_GET['action'] ?? '';

function inc_row(array $r): array {
    foreach (['priority', 'torino', 'palermo', 'pc'] as $k) $r[$k] = $r[$k] === null ? null : (float)$r[$k];
    $r['owner_id'] = $r['owner_id'] === null ? null : (int)$r['owner_id'];
    return $r;
}

try {
    $me = require_login();

    if ($action === 'list') {
        $domain = $_GET['domain'] ?? null;
        $stateF = $_GET['state'] ?? 'active';
        $w = []; $v = [];
        if ($domain === 'ssa' || $domain === 'neo') { $w[] = 'domain=?'; $v[] = $domain; }
        if ($stateF === 'active') { $w[] = "state NOT IN ('resolved','closed')"; }
        elseif ($stateF === 'resolved') { $w[] = "state IN ('resolved','closed')"; }
        $sql = 'SELECT * FROM app_incident' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY priority IS NULL, priority ASC';
        $st = app_db()->prepare($sql); $st->execute($v);
        json_out(['ok' => true, 'incidents' => array_map('inc_row', $st->fetchAll())]);
    }

    if ($action === 'get') {
        $id = (string)($_GET['id'] ?? '');
        $st = app_db()->prepare('SELECT * FROM app_incident WHERE id=?'); $st->execute([$id]);
        $r = $st->fetch();
        if (!$r) json_err('not found', 404);
        $au = app_db()->prepare('SELECT ts,actor_name,from_state,to_state,note FROM app_incident_audit WHERE incident_id=? ORDER BY ts');
        $au->execute([$id]);
        json_out(['ok' => true, 'incident' => inc_row($r), 'audit' => $au->fetchAll()]);
    }

    if ($action === 'upsert') {
        check_csrf();
        if (!can_work($me)) json_err('forbidden', 403);
        $b = body_json();
        $id = (string)($b['id'] ?? '');
        $domain = ($b['domain'] ?? '') === 'neo' ? 'neo' : 'ssa';
        $object = trim((string)($b['object'] ?? ''));
        if ($id === '' || $object === '') json_err('id and object required');
        $sev = in_array(($b['severity'] ?? ''), ['critical', 'high', 'elevated', 'routine'], true) ? $b['severity'] : 'routine';
        $tca = !empty($b['tca_utc']) ? date('Y-m-d H:i:s', (int)($b['tca_utc'] / 1000)) : null;
        // alta nueva conserva opened_utc y state; actualización no piso state ni owner
        $exists = app_db()->prepare('SELECT id FROM app_incident WHERE id=?'); $exists->execute([$id]);
        if ($exists->fetch()) {
            $st = app_db()->prepare('UPDATE app_incident SET severity=?,priority=?,recommended_action=?,kind=?,metric=?,tca_utc=?,torino=?,palermo=?,obsv=?,pc=COALESCE(?,pc),updated_at=NOW() WHERE id=?');
            $st->execute([$sev, $b['priority'] ?? null, $b['recommended_action'] ?? null, $b['kind'] ?? null, $b['metric'] ?? null, $tca, $b['torino'] ?? null, $b['palermo'] ?? null, $b['obsv'] ?? null, $b['pc'] ?? null, $id]);
        } else {
            $st = app_db()->prepare('INSERT INTO app_incident (id,domain,object,kind,severity,priority,recommended_action,state,metric,tca_utc,torino,palermo,obsv,pc,opened_utc,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())');
            $st->execute([$id, $domain, $object, $b['kind'] ?? null, $sev, $b['priority'] ?? null, $b['recommended_action'] ?? null, 'new', $b['metric'] ?? null, $tca, $b['torino'] ?? null, $b['palermo'] ?? null, $b['obsv'] ?? null, $b['pc'] ?? null]);
            app_db()->prepare('INSERT INTO app_incident_audit (incident_id,ts,actor_id,actor_name,from_state,to_state,note) VALUES (?,NOW(),?,?,?,?,?)')
                ->execute([$id, (int)$me['id'], $me['name'], null, 'new', $b['kind'] ?? '']);
        }
        json_out(['ok' => true]);
    }

    if ($action === 'transition') {
        check_csrf();
        if (!can_work($me)) json_err('forbidden', 403);
        $b = body_json();
        $id = (string)($b['id'] ?? '');
        $to = (string)($b['to'] ?? '');
        $note = substr(trim((string)($b['note'] ?? '')), 0, 255);
        $st = app_db()->prepare('SELECT state,owner_id FROM app_incident WHERE id=?'); $st->execute([$id]);
        $cur = $st->fetch();
        if (!$cur) json_err('not found', 404);
        $from = $cur['state'];
        if (!lc_can($from, $to)) json_err("invalid transition $from -> $to", 409);
        $sets = ['state=?', 'updated_at=NOW()']; $vals = [$to];
        if ($to !== 'new' && $cur['owner_id'] === null) { $sets[] = 'owner_id=?'; $vals[] = (int)$me['id']; }
        if ($to === 'acknowledged') $sets[] = 'ack_utc=COALESCE(ack_utc,NOW())';
        if ($to === 'resolved') $sets[] = 'resolved_utc=NOW()';
        if ($to === 'closed') $sets[] = 'closed_utc=NOW()';
        if ($to === 'new') $sets[] = 'resolved_utc=NULL';
        $vals[] = $id;
        app_db()->prepare('UPDATE app_incident SET ' . implode(',', $sets) . ' WHERE id=?')->execute($vals);
        app_db()->prepare('INSERT INTO app_incident_audit (incident_id,ts,actor_id,actor_name,from_state,to_state,note) VALUES (?,NOW(),?,?,?,?,?)')
            ->execute([$id, (int)$me['id'], $me['name'], $from, $to, $note]);
        log_activity((int)$me['id'], $me['name'], 'incident_transition', $id . ' ' . $from . '->' . $to);
        json_out(['ok' => true]);
    }

    json_err('unknown action', 404);
} catch (Throwable $e) {
    json_err('server error', 500);
}
