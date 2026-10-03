<?php

/**
 * Clinical-sign thresholds.
 *
 * `sourced`: values with a verifiable clinical origin (Hemocentro visit).
 * Only these may gate a donation. Hemoglobin >= 12.5 g/dL and donor body
 * weight >= 50 kg are the sourced pair.
 *
 * `capture_only`: fields recorded for the hemocentro but with UNVERIFIED
 * thresholds. They are captured and never gated; replace with real values
 * once a clinical source exists.
 */
return [
    'sourced' => [
        'hemoglobina' => [
            'min' => 12.5,
            'code' => 'HEMOGLOBINA_BAJA',
            'unit' => 'g/dL',
        ],
        'peso_donante' => [
            'min' => 50.0,
            'code' => 'PESO_BAJO',
            'unit' => 'kg',
        ],
    ],

    'capture_only' => [
        'hematocrito',
        'plaquetas',
        'presion_sistolica',
        'presion_diastolica',
        'frecuencia_cardiaca',
    ],
];
