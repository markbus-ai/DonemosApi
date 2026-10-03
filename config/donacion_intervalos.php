<?php

/**
 * Minimum interval between whole-blood donations, by donor sex.
 *
 * Sourced from the Hemocentro: men may donate every 4 months, women every
 * 6 months. Kept in config so the clinical value can be adjusted without
 * touching rule logic.
 *
 * `min_por_sexo` maps the patient `sexo` value to a number of months. A sex
 * that is not listed here (including null/unknown) disables the check rather
 * than guessing an interval.
 */
return [
    'min_por_sexo' => [
        'M' => 4,
        'F' => 6,
    ],
];
