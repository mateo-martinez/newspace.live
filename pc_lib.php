<?php
/**
 * pc_lib.php  -  Fase 4: probabilidad de colisión 2D (Foster), portada de pc_engine.js.
 * Lógica pura, sin BD. Recibe miss y covarianza YA PROYECTADOS al plano de encuentro.
 *
 * Uso operativo: los CDM públicos de Space-Track ya traen la Pc del 18th SDS, que es
 * la que usa el worker. Este motor sirve para recalcular/verificar cuando tenés la
 * covarianza 2D (CDM de operador o feed comercial). La proyección 3D->2D desde la
 * covarianza RTN cruda es un paso aparte que requiere validación contra casos de
 * referencia y NO se asume acá.
 */
declare(strict_types=1);

/** Pc 2D por integración de la gaussiana de posición relativa sobre el disco de cuerpo duro. */
function pc2d(float $missX, float $missY, float $cxx, float $cyy, float $cxy, float $hbr, int $nr = 200, int $nth = 200): float {
    $det = $cxx * $cyy - $cxy * $cxy;
    if ($det <= 0 || $hbr <= 0) return NAN;
    $i00 = $cyy / $det; $i11 = $cxx / $det; $i01 = -$cxy / $det;
    $norm = 1 / (2 * M_PI * sqrt($det));
    $dr = $hbr / $nr; $dth = 2 * M_PI / $nth; $sum = 0.0;
    for ($i = 0; $i < $nr; $i++) {
        $r = ($i + 0.5) * $dr; $w = $r * $dr * $dth;
        for ($j = 0; $j < $nth; $j++) {
            $th = ($j + 0.5) * $dth;
            $dx = $r * cos($th) - $missX; $dy = $r * sin($th) - $missY;
            $q = $i00 * $dx * $dx + 2 * $i01 * $dx * $dy + $i11 * $dy * $dy;
            $sum += $norm * exp(-0.5 * $q) * $w;
        }
    }
    return $sum;
}

/** Banda de severidad por Pc (umbrales operativos estándar). */
function pc_band(?float $pc): string {
    if ($pc === null || is_nan($pc)) return 'unknown';
    if ($pc >= 1e-4) return 'critical';
    if ($pc >= 1e-5) return 'high';
    if ($pc >= 1e-7) return 'elevated';
    return 'routine';
}

/** Banda de respaldo por distancia mínima (km) cuando no hay Pc. Proxy, se etiqueta como tal. */
function miss_band(?float $missKm): string {
    if ($missKm === null) return 'routine';
    if ($missKm < 1) return 'high';
    if ($missKm < 5) return 'elevated';
    return 'routine';
}
