<?php
/**
 * swe_lib.php  -  Tramo 1: escalas de clima espacial de NOAA a severidad e impacto.
 *
 * NOAA publica tres escalas 0..5 (https://services.swpc.noaa.gov/products/noaa-scales.json):
 *   G = tormenta geomagnetica (Kp)         -> red electrica, GNSS, HF, arrastre LEO, aurora
 *   S = tormenta de radiacion solar        -> satelites, astronautas, HF polar
 *   R = apagon de radio (fulguraciones X)  -> HF en el lado diurno, degradacion de GNSS
 *
 * Libreria pura: sin red ni base de datos. El worker traduce estas escalas a incidentes.
 * El sistema es integrador y traductor a impacto, no genera modelos ni pronosticos.
 */
declare(strict_types=1);

/** Escala NOAA 0..5 a nivel de severidad interno. */
function swe_severity(int $scale): string {
    if ($scale >= 4) return 'critical';   // severe / extreme
    if ($scale === 3) return 'high';       // strong
    if ($scale === 2) return 'elevated';   // moderate
    if ($scale === 1) return 'routine';    // minor
    return 'none';                          // 0
}

/** Nombre del tipo de evento por letra de escala. */
function swe_kind_label(string $k): string {
    switch (strtoupper($k)) {
        case 'G': return 'Geomagnetic storm';
        case 'S': return 'Solar radiation storm';
        case 'R': return 'Radio blackout';
        default:  return 'Space weather';
    }
}

/** Impacto operativo por tipo y escala. Texto conciso, orientado a defensa. */
function swe_impact(string $k, int $scale): string {
    $k = strtoupper($k);
    if ($k === 'G') {
        if ($scale >= 4) return 'GNSS degraded, HF disrupted, increased LEO drag, power-grid risk, low-lat aurora';
        if ($scale === 3) return 'GNSS intermittent, HF fading at high lat, higher LEO drag';
        return 'Minor GNSS/HF effects at high latitude';
    }
    if ($k === 'R') {
        if ($scale >= 4) return 'Wide HF blackout on sunlit side, GNSS degradation';
        if ($scale === 3) return 'HF blackout on sunlit side for tens of minutes';
        return 'Weak HF degradation on sunlit side';
    }
    if ($k === 'S') {
        if ($scale >= 4) return 'Satellite/electronics risk, polar HF blackout, radiation hazard';
        if ($scale === 3) return 'Possible satellite single-event effects, polar HF degraded';
        return 'Minor satellite/polar HF effects';
    }
    return 'See space weather module';
}

/** Banda de prioridad (menor es mas urgente), coherente con el resto del sistema. */
function swe_priority(string $sev): int {
    $rank = ['critical' => 0, 'high' => 1, 'elevated' => 2, 'routine' => 3];
    return ($rank[$sev] ?? 3) * 1000;
}
