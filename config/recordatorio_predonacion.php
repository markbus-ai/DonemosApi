<?php

/**
 * Pre-donation reminder defaults.
 *
 * `plantilla` is rendered once into the stored reminder body; the supported
 * placeholders are `:nombre`, `:fecha`, `:hora` and `:sede`. Only donor name,
 * appointment date/time and site may appear (no DNI or clinical data).
 *
 * Delivery is the batched WhatsApp job (config/whatsapp.php picks the driver).
 */
return [
    'canal' => 'WHATSAPP',
    // RECORDATORIO (kept as `plantilla` for compatibility).
    'plantilla' => 'Hola :nombre, te recordamos tu turno de donación el :fecha a las :hora en :sede.',
    'plantillas' => [
        'CONFIRMACION' => 'Hola :nombre, tu turno de donación quedó agendado para el :fecha a las :hora en :sede. ¡Gracias por donar!',
        'CANCELACION' => 'Hola :nombre, tu turno de donación del :fecha a las :hora en :sede fue cancelado. Podés pedir uno nuevo cuando quieras.',
    ],
];
