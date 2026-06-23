<?php
/**
 * fragmentation_lib.php  -  Tramo 1 (SST): análisis de fragmentación.
 *
 * Implementa el Modelo de Ruptura Estándar de la NASA (Johnson et al., 2001) para estimar
 * el número acumulado de fragmentos por tamaño tras una explosión o una colisión, más una
 * estimación de la banda de altitud afectada por la dispersión de velocidad de los fragmentos.
 *
 * Fórmulas (Lc = longitud característica en metros, masa en kg):
 *   Explosión:  N(>Lc) = 6 * S * Lc^(-1.6)
 *   Colisión:   N(>Lc) = 0.1 * Mc^(0.75) * Lc^(-1.71)
 * Mc (masa característica): catastrófica -> Mt + Mp ; no catastrófica -> Mp * v^2 (v en km/s).
 * Catastrófica si EMR = energía de impacto / masa del blanco >= 40 J/g.
 *
 * HONESTO: es el modelo estándar de poblaciones, no una simulación de cada fragmento ni una
 * propagación de la nube. Da órdenes de magnitud y la región orbital afectada, no trayectorias.
 */
declare(strict_types=1);

const FR_GM = 398600.4418;   // km^3/s^2
const FR_RE = 6378.137;      // km

/** Número acumulado de fragmentos con longitud característica >= $lc (m). */
function sbm_count(string $type, float $charMass, float $lc): float {
    if ($lc <= 0) return 0.0;
    if ($type === 'explosion') return 6.0 * pow($lc, -1.6);
    return 0.1 * pow(max($charMass, 0.0), 0.75) * pow($lc, -1.71); // collision
}

/** ¿Colisión catastrófica? EMR = 0.5*Mp*v^2 / Mt en J/g (v en km/s). Umbral 40 J/g. */
function sbm_is_catastrophic(float $mTarget, float $mProj, float $vKmS): bool {
    if ($mTarget <= 0) return true;
    $energyJ = 0.5 * $mProj * pow($vKmS * 1000.0, 2);   // J
    $emr = $energyJ / ($mTarget * 1000.0);              // J/g
    return $emr >= 40.0;
}

/** Masa característica para colisión. */
function sbm_char_mass(float $mTarget, float $mProj, float $vKmS): float {
    return sbm_is_catastrophic($mTarget, $mProj, $vKmS) ? ($mTarget + $mProj) : ($mProj * $vKmS * $vKmS);
}

/** Conteo por bins de tamaño: >1 cm, >10 cm (rastreables), >1 m. */
function sbm_bins(string $type, float $charMass): array {
    return [
        'gt_1cm'  => sbm_count($type, $charMass, 0.01),
        'gt_10cm' => sbm_count($type, $charMass, 0.10),   // umbral de catalogación en LEO
        'gt_1m'   => sbm_count($type, $charMass, 1.0),
    ];
}

/** Velocidad circular (km/s) a una altitud dada (km). */
function fr_vcirc(float $altKm): float {
    return sqrt(FR_GM / (FR_RE + $altKm));
}

/**
 * Banda de altitud afectada (km, +/-) por una dispersión de velocidad $dvKmS.
 * Linealización: dr ~ 2 * r * (dv / vCirc).
 */
function fr_altitude_band(float $altKm, float $dvKmS): float {
    $r = FR_RE + $altKm;
    return 2.0 * $r * ($dvKmS / fr_vcirc($altKm));
}
