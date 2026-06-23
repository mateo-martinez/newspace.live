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
    'email'    => [ 'to' => '', 'from' => 'ssa@newspace.live' ],
    'alert'    => [ 'rate_per_min' => 12 ],

    // ---------------- Base de datos ----------------
    'db' => [
        'host' => 'localhost',
        'name' => 'TU_USUARIO_ssa',
        'user' => 'TU_USUARIO_ssa',
        'pass' => 'TU_PASSWORD',
    ],

    // ---------------- Ingesta de TLE ----------------
    'ingest' => [
        'token'         => 'CAMBIAME_token_largo_y_secreto',
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
        'password' => 'CAMBIAME',
        'query'    => 'cdm_public',
    ],

    // ---------------- Tramo 1: SOCRATES (prevención de colisión, datos públicos) ----------------
    // CelesTrak SOCRATES Plus, sin login. CSV crudo completo (ordenado por mínima distancia).
    // MAX_PROB es la probabilidad MAXIMA (techo conservador), no la Pc real. Sin covarianza propia.
    // El CSV trae miles de conjunciones: min_prob y max_miss_km filtran las que entran a la cola.
    // Verificá con la prueba en seco (?dry=1). Doc: https://celestrak.org/SOCRATES/socrates-format.php
    'socrates' => [
        'url'         => 'https://celestrak.org/SOCRATES-Plus/sort-minRange.csv',
        'min_prob'    => 1e-5,   // crea incidente si MAX_PROB >= este valor
        'max_miss_km' => 1.0,    // o si la distancia mínima <= estos km (pasos muy cercanos)
    ],

    // ---------------- Tramo 1: Clima espacial (NOAA SWPC) ----------------
    // Escalas G/R/S de NOAA. min_scale fija a partir de qué escala se crea incidente
    // (1 minor, 2 moderate, 3 strong, 4 severe, 5 extreme).
    'swe' => [
        'scales_url' => 'https://services.swpc.noaa.gov/products/noaa-scales.json',
        'min_scale'  => 2,
    ],

    // ---------------- Tramo 1: Reentrada (SST, datos públicos) ----------------
    // Baja un grupo GP (OMM JSON) y marca candidatos a reentrada por perigeo bajo.
    // 'url' puede apuntar a cualquier grupo de CelesTrak; perigee_km es el umbral.
    // 'aoi' es el rango de latitudes del área de interés (Uruguay por defecto).
    // HONESTO: estimación de orden de magnitud, no hora ni punto de impacto precisos.
    'reentry' => [
        'url'        => 'https://celestrak.org/NORAD/elements/gp.php?GROUP=active&FORMAT=json',
        'perigee_km' => 300.0,
        'aoi'        => [-35.0, -30.0],
    ],

    // ---------------- Tramo 1: SDA (caracterización de maniobras) ----------------
    // Lee tle_history y caracteriza maniobras (delta-v, tipo). min_dv_ms es el delta-v
    // mínimo para considerar maniobra; min_di_deg el cambio de inclinación mínimo.
    // HONESTO: indicio sobre elementos públicos, no certeza ni intención.
    'sda' => [
        'min_dv_ms'  => 5.0,
        'min_di_deg' => 0.03,
    ],
];
