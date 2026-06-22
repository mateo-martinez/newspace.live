<?php
/**
 * db.php  -  Conexión PDO a MySQL a partir de config.php.
 * Se incluye desde ingest.php y tle.php (no se accede por web).
 */
declare(strict_types=1);

function ssa_db(array $cfg): PDO
{
    $d = $cfg['db'] ?? [];
    $host = $d['host'] ?? 'localhost';
    $name = $d['name'] ?? '';
    $user = $d['user'] ?? '';
    $pass = $d['pass'] ?? '';
    $dsn  = "mysql:host={$host};dbname={$name};charset=utf8mb4";
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
}
