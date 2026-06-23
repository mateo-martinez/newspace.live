<?php
/**
 * socrates_lib.php  -  Tramo 1: mapeo de un registro de conjuncion de CelesTrak SOCRATES.
 *
 * SOCRATES (Satellite Orbital Conjunction Reports Assessing Threatening Encounters
 * in Space) publica conjunciones calculadas sobre elementos PUBLICOS. La probabilidad
 * que entrega es de CRIBADO (probabilidad maxima sobre incertidumbre derivada del TLE),
 * NO una Pc con covarianza real. Se usa como sustituto del CDM y se rotula como tal.
 *
 * Esta libreria es pura y testeable: no toca red ni base de datos. Tolera variantes de
 * nombres de campo porque el formato exacto de CelesTrak puede variar; el worker trae un
 * modo de prueba en seco para verificar el mapeo contra el feed real antes de operar.
 */
declare(strict_types=1);
require_once __DIR__ . '/pc_lib.php';

/** Devuelve el primer valor presente y no vacio entre varios alias de clave. */
function soc_pick(array $row, array $keys, $default = null) {
    foreach ($keys as $k) {
        if (array_key_exists($k, $row) && $row[$k] !== '' && $row[$k] !== null) return $row[$k];
    }
    return $default;
}

/**
 * Parsea el cuerpo de la respuesta de SOCRATES, sea JSON o CSV.
 * JSON: array de objetos (tolera envoltorio {data:[...]}).
 * CSV: primera linea encabezado, arma cada fila como mapa nombre->valor.
 */
function socrates_parse(string $body): array {
    $t = ltrim($body);
    if ($t === '') return [];
    if ($t[0] === '[' || $t[0] === '{') {
        $j = json_decode($body, true);
        if (!is_array($j)) return [];
        if (isset($j['data']) && is_array($j['data'])) return $j['data'];
        return $j;
    }
    $lines = preg_split('/\r\n|\r|\n/', trim($body));
    if (!$lines || count($lines) < 2) return [];
    $header = array_map('trim', str_getcsv(array_shift($lines)));
    $out = [];
    foreach ($lines as $ln) {
        if (trim($ln) === '') continue;
        $cells = str_getcsv($ln);
        if (count($cells) < 2) continue;
        $row = [];
        foreach ($header as $i => $h) { if ($h !== '') $row[$h] = $cells[$i] ?? null; }
        $out[] = $row;
    }
    return $out;
}

/**
 * Normaliza un registro de conjuncion a los campos del incidente.
 * Devuelve null si faltan datos minimos (los dos objetos y el TCA).
 */
function soc_map(array $row): ?array {
    $id1 = soc_pick($row, ['SAT_1_ID', 'NORAD_CAT_ID_1', 'OBJECT1_NORAD', 'ID1', 'sat1_id']);
    $id2 = soc_pick($row, ['SAT_2_ID', 'NORAD_CAT_ID_2', 'OBJECT2_NORAD', 'ID2', 'sat2_id']);
    $n1  = soc_pick($row, ['SAT_1_NAME', 'OBJECT_NAME_1', 'OBJECT1', 'NAME1', 'sat1_name'], 'SAT1');
    $n2  = soc_pick($row, ['SAT_2_NAME', 'OBJECT_NAME_2', 'OBJECT2', 'NAME2', 'sat2_name'], 'SAT2');
    $tca = soc_pick($row, ['TCA', 'tca', 'TCA_UTC']);
    if ($id1 === null || $id2 === null || $tca === null) return null;
    $tcaTs = strtotime((string)$tca);
    if ($tcaTs === false) return null;

    $missRaw = soc_pick($row, ['TCA_RANGE', 'MIN_RNG', 'MIN_RANGE', 'MISS_DISTANCE', 'miss_distance', 'RANGE']);
    $miss = $missRaw !== null ? (float)$missRaw : null;          // km
    $probRaw = soc_pick($row, ['MAX_PROB', 'MAX_PROBABILITY', 'max_probability', 'PC', 'COLLISION_PROBABILITY']);
    $pc = $probRaw !== null ? (float)$probRaw : null;            // probabilidad MAXIMA (techo conservador)
    $velRaw = soc_pick($row, ['TCA_RELATIVE_SPEED', 'RELATIVE_SPEED', 'REL_SPEED', 'TCA_RELATIVE_VELOCITY']);
    $relVel = $velRaw !== null ? (float)$velRaw : null;
    $dse1 = soc_pick($row, ['DSE_1', 'dse_1']);
    $dse2 = soc_pick($row, ['DSE_2', 'dse_2']);
    $dse = ($dse1 !== null || $dse2 !== null) ? max((float)$dse1, (float)$dse2) : null; // dias desde epoca (calidad)
    $dilRaw = soc_pick($row, ['DILUTION', 'dilution']);
    $dilution = $dilRaw !== null ? (float)$dilRaw : null;

    $sev = $pc !== null ? pc_band($pc) : miss_band($miss);
    if ($sev === 'unknown') $sev = 'routine';

    return [
        'id1' => (string)$id1, 'id2' => (string)$id2,
        'name1' => trim((string)$n1), 'name2' => trim((string)$n2),
        'tcaTs' => $tcaTs, 'tcaSql' => date('Y-m-d H:i:s', $tcaTs),
        'miss' => $miss, 'pc' => $pc, 'relVel' => $relVel, 'dse' => $dse, 'dilution' => $dilution, 'severity' => $sev,
    ];
}
