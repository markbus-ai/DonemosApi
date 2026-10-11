<?php

/**
 * Blood-bank donation-cycle odometer limits.
 *
 * A donor advances one position per donation: `donacion` counts 1..N within a
 * `serie`, `serie` counts 1..M within a `ciclo`, and overflowing the serie opens
 * the next ciclo. Kept in config so the clinical limits can be adjusted without
 * touching service logic.
 */
return [
    'donaciones_por_serie' => 5,
    'series_por_ciclo' => 3,
];
