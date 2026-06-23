<?php
/**
 * reentry_lib.php  -  Tramo 1: análisis de reentrada sobre datos públicos (CelesTrak GP).
 *
 * Lógica pura y testeable. Identifica candidatos a reentrada por altura de perigeo,
 * estima una ventana GRUESA de vida útil y una huella como banda de latitud (±inclinación),
 * y evalúa solapamiento con un área de interés.
 *
 * HONESTO: con datos públicos y sin integrador de arrastre validado, esto es una estimación
 * de orden de magnitud, NO una predicción precisa de hora ni de punto de impacto. La ventana
 * real depende de la densidad atmosférica (clima espacial), la actitud y el área/masa.
 */
declare(strict_types=1);

const RE_GM = 398600.4418;   // km^3/s^2
const RE_RE = 6378.137;      // km, radio ecuatorial

/** Semieje mayor (km) a partir del movimiento medio en rev/día. */
function re_semimajor(float $meanMotionRevDay): float {
    if ($meanMotionRevDay <= 0) return 0.0;
    $nRad = $meanMotionRevDay * 2 * M_PI / 86400.0;
    return pow(RE_GM / ($nRad * $nRad), 1.0 / 3.0);
}

/** Altura de perigeo (km sobre el ecuador medio). */
function re_perigee_km(float $meanMotionRevDay, float $ecc): float {
    $a = re_semimajor($meanMotionRevDay);
    return $a * (1.0 - $ecc) - RE_RE;
}

/** Altura de apogeo (km). */
function re_apogee_km(float $meanMotionRevDay, float $ecc): float {
    $a = re_semimajor($meanMotionRevDay);
    return $a * (1.0 + $ecc) - RE_RE;
}

/**
 * Banda de vida útil por altura de perigeo. Regla de orden de magnitud, etiquetada como tal.
 * Devuelve [etiqueta, dias_nominales|null].
 */
function re_lifetime_band(float $perigeeKm): array {
    if ($perigeeKm < 130) return ['imminent', 0.5];
    if ($perigeeKm < 180) return ['days', 4];
    if ($perigeeKm < 220) return ['weeks', 20];
    if ($perigeeKm < 300) return ['months', 120];
    return ['stable', null];
}

/** Severidad por altura de perigeo (y opcional solapamiento con AOI). */
function re_severity(float $perigeeKm, bool $aoiOverlap = false): string {
    $s = 'routine';
    if ($perigeeKm < 150) $s = 'critical';
    elseif ($perigeeKm < 200) $s = 'high';
    elseif ($perigeeKm < 260) $s = 'elevated';
    // un solapamiento con el área de interés sube un escalón (sin pasar de crítico)
    if ($aoiOverlap) {
        $up = ['routine' => 'elevated', 'elevated' => 'high', 'high' => 'critical', 'critical' => 'critical'];
        $s = $up[$s];
    }
    return $s;
}

/** Alcance de latitud de la huella: el objeto sobrevuela latitudes ±latReach. */
function re_lat_reach(float $inclDeg): float {
    $i = abs($inclDeg);
    if ($i > 90) $i = 180 - $i;       // retrógrada
    return min(90.0, $i);
}

/** ¿La huella (±latReach) solapa el rango de latitudes del área de interés? */
function re_aoi_overlap(float $inclDeg, float $aoiLatMin, float $aoiLatMax): bool {
    $r = re_lat_reach($inclDeg);
    return !($aoiLatMax < -$r || $aoiLatMin > $r);
}
