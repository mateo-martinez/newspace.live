<?php
/**
 * sda_lib.php  -  Tramo 1 (SDA): caracterización de comportamiento desde la historia de elementos.
 *
 * Robustece la detección de maniobras del worker de TLE: en vez de un flag binario, caracteriza
 * el cambio orbital entre dos épocas, estima el delta-v y el tipo (subida, bajada, cambio de
 * plano), separa lo que explica el arrastre, y para objetos geoestacionarios estima la deriva.
 *
 * Todo es lógica pura y testeable, sobre elementos PUBLICOS (movimiento medio, inclinación).
 *
 * HONESTO: lo que se infiere de elementos públicos es INDICIO, no certeza. Un cambio puede ser
 * una maniobra, un error del elemento, o el inicio de una reentrada. Nunca se afirma intención
 * de forma automática; se entrega como indicio para que el analista juzgue.
 */
declare(strict_types=1);

const SDA_GM = 398600.4418;             // km^3/s^2
const SDA_N_GEO = 1.00273790935;        // rev/día sidéreo (geoestacionario)

/** Semieje mayor (km) desde el movimiento medio (rev/día). */
function sda_semimajor(float $nRevDay): float {
    if ($nRevDay <= 0) return 0.0;
    $nRad = $nRevDay * 2 * M_PI / 86400.0;
    return pow(SDA_GM / ($nRad * $nRad), 1.0 / 3.0);
}

/** Velocidad circular (km/s) a un semieje dado. */
function sda_vcirc(float $aKm): float {
    return $aKm > 0 ? sqrt(SDA_GM / $aKm) : 0.0;
}

/**
 * Cambio de semieje (km) y delta-v tangencial (km/s) que explican un cambio de movimiento
 * medio $dn (rev/día). Linealización: da = -(2/3)(a/n)dn ; dv_tan = 0.5 v |da|/a.
 */
function sda_dv_from_dn(float $nRevDay, float $dn): array {
    $a = sda_semimajor($nRevDay);
    $da = -(2.0 / 3.0) * ($a / $nRevDay) * $dn;     // km (signo: dn<0 sube la órbita)
    $v = sda_vcirc($a);
    $dv = $a > 0 ? 0.5 * $v * abs($da) / $a : 0.0;  // km/s
    return ['da' => $da, 'dv' => $dv];
}

/** Delta-v (km/s) de un cambio de plano de $diDeg grados: dv = v * di(rad). */
function sda_dv_plane(float $aKm, float $diDeg): float {
    return sda_vcirc($aKm) * deg2rad(abs($diDeg));
}

/** Deriva geoestacionaria (grados/día). ~0 si está en estación; crece si deriva. */
function sda_geo_drift(float $nRevDay): float {
    return (SDA_N_GEO - $nRevDay) * 360.0;
}

/** ¿El objeto está en el régimen geoestacionario? (semieje cerca de 42164 km, baja inclinación) */
function sda_is_geo(float $nRevDay, float $inclDeg): bool {
    $a = sda_semimajor($nRevDay);
    return $a > 41000 && $a < 43500 && abs($inclDeg) < 20;
}

/**
 * Caracteriza un cambio entre dos épocas.
 *   $n       movimiento medio actual (rev/día)
 *   $dnResid cambio de mov. medio ya descontado el arrastre (rev/día, con signo)
 *   $diDeg   cambio de inclinación (grados, magnitud)
 * Devuelve indicio de maniobra, tipo, delta-v estimado (m/s) y severidad.
 */
function sda_classify(float $n, float $dnResid, float $diDeg, float $minDvKmS = 0.005, float $minDiDeg = 0.03): array {
    $a = sda_semimajor($n);
    $r = sda_dv_from_dn($n, $dnResid);
    $dvTan = $r['dv']; $da = $r['da'];
    $dvPlane = sda_dv_plane($a, $diDeg);
    $dvTotal = $dvTan + $dvPlane;                       // km/s (cota conservadora)

    $maneuver = ($dvTotal >= $minDvKmS) || ($diDeg >= $minDiDeg);
    $type = 'none';
    if ($diDeg >= $minDiDeg && $dvPlane >= $dvTan) $type = 'plane';
    elseif ($da > 0.05) $type = 'raise';
    elseif ($da < -0.05) $type = 'lower';
    elseif ($maneuver) $type = 'combined';

    $dvMs = $dvTotal * 1000.0;
    $sev = 'routine';
    if ($maneuver) {
        if ($type === 'plane' || $dvMs >= 50) $sev = 'high';
        elseif ($dvMs >= 10) $sev = 'elevated';
        else $sev = 'routine';
    }
    return [
        'maneuver' => $maneuver, 'type' => $type,
        'dv_ms' => round($dvMs, 1), 'da_km' => round($da, 1),
        'di_deg' => round($diDeg, 3), 'severity' => $sev,
    ];
}
