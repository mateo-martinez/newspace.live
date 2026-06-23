<?php
/**
 * neocp_lib.php  -  Tramo 1 (NEO): respuesta rápida sobre la NEO Confirmation Page del MPC.
 *
 * La NEOCP lista descubrimientos nuevos sin confirmar que necesitan astrometría de
 * seguimiento rápida antes de perderse. Esta librería decide, de forma pura y testeable,
 * cuáles puede observar un sitio (por altura de culminación) y con qué prioridad.
 *
 * HONESTO: la altura de culminación es la altura MAXIMA posible del objeto desde el sitio,
 * una condición necesaria de observabilidad, no una solución de apuntado ni una ventana
 * horaria. El apuntado real en altura y azimut a una hora dada necesita efemérides.
 */
declare(strict_types=1);

/** Altura de culminación (grados) de un objeto de declinación $decDeg desde latitud $latDeg. */
function neocp_culmination_alt(float $latDeg, float $decDeg): float {
    return 90.0 - abs($latDeg - $decDeg);   // puede ser negativa: nunca sube
}

/** ¿Observable desde el sitio? La culminación supera la altura mínima útil. */
function neocp_observable(float $latDeg, float $decDeg, float $minAltDeg = 20.0): bool {
    return neocp_culmination_alt($latDeg, $decDeg) >= $minAltDeg;
}

/** Prioridad de seguimiento por puntaje NEO (0..100) del MPC. */
function neocp_severity(float $score): string {
    if ($score >= 80) return 'high';       // muy probable NEO: asegurar la órbita
    if ($score >= 50) return 'elevated';
    return 'routine';
}

/** Acción recomendada: observar si es alcanzable y observable, si no monitorear. */
function neocp_action(bool $observable, ?float $vmag, float $magLimit): string {
    $reachable = ($vmag === null) ? true : ($vmag <= $magLimit);
    return ($observable && $reachable) ? 'observe' : 'monitor';
}
