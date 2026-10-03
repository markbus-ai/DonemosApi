<?php

/**
 * Platelet-enable window defaults.
 *
 * `meses` is the default enabling duration applied when the operator does not
 * supply an explicit `hasta`. The expiry day itself is inactive: an enable is
 * active iff `desde <= D < hasta`.
 */
return [
    'meses' => 6,
];
