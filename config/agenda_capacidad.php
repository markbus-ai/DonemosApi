<?php

/**
 * Daily booking capacity, expressed as a duration sum.
 *
 * `minutos_por_dia` is the total minutes of appointments a single sede may
 * hold on one date. `duracion_minima` is the floor applied to any candidate
 * turno whose donation type has no explicit duration (or none was supplied).
 *
 * These are product assumptions pending Hemocentro sign-off (spec A2).
 */
return [
    'minutos_por_dia' => 480,
    'duracion_minima' => 15,
];
