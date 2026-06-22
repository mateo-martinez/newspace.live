<?php
/**
 * config.sample.php  ->  copiá a  config.php  y completá tus valores.
 *
 * config.php guarda los SECRETOS del lado servidor (tokens, credenciales).
 * NUNCA llegan al navegador. El .htaccess incluido bloquea su acceso por web.
 *
 * SEGURIDAD: no pegues credenciales en chats ni en el repositorio. Si una
 * credencial se expuso, rotala (cambiala) en el proveedor antes de usarla acá.
 */

return [

    'allowed_origins' => ['https://newspace.live'],
    'neows_key' => '',

    // ---------------- Relay de alertas ----------------
    'telegram' => [ 'token' => '', 'chat_id' => '' ],
    'email'    => [ 'to' => '', 'from' => '' ],
    'alert'    => [ 'rate_per_min' => 12 ],

    // ---------------- Base de datos ----------------
    'db' => [
        'host' => 'localhost',
        'name' => '',
        'user' => '',
        'pass' => '',
    ],

    // ---------------- Ingesta de TLE ----------------
    'ingest' => [
        'token'         => '',
        'groups'        => ['visual'],
        'max_objects'   => 0,
        'man_dinc_deg'  => 0.03,
        'man_dmm_raise' => 1e-4,
    ],

    // ---------------- Space-Track (Fase 4: CDM / covarianza) ----------------
    // Cuenta en https://www.space-track.org . La password va SOLO acá, nunca en el front.
    // Si la expusiste alguna vez, rotala en Space-Track y poné la nueva.
    // CDM privados (tus conjunciones) requieren ser operador; cdm_public es abierto.
    'space_track' => [
        'identity' => '',
        'password' => '',
        'query'    => 'cdm_public',
    ],
];
