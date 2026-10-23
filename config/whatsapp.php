<?php

/**
 * WhatsApp delivery for turno messages.
 *
 * `driver`:
 * - `log` (default): nothing leaves the machine; the message is written to the
 *   log and the row is marked ENVIADO. Safe for demos and tests.
 * - `whatsplay`: sends through WhatsPlay (whatsplay-automation skill) via
 *   scripts/whatsplay_turnos.py, with the skill's uv environment and the only
 *   working session in `whatsplay.dir`/whatsapp_session. WHATSPLAY_DIR is
 *   required for this driver (the skill checkout, e.g. /opt/whatsapp-skill);
 *   without it nothing is sent and the messages fail with a clear reason.
 *
 * `numero_prueba`: outside production every message is redirected to this
 * number; without it, the whatsplay driver refuses to send. Demo phones are
 * fake and may belong to real people.
 */
return [
    'driver' => env('WHATSAPP_DRIVER', 'log'),

    'numero_prueba' => env('WHATSAPP_NUMERO_PRUEBA'),

    'whatsplay' => [
        'dir' => env('WHATSPLAY_DIR'),
        // WhatsApp Web start-up plus the server tick can take a while.
        'timeout' => (int) env('WHATSPLAY_TIMEOUT', 180),
    ],

    // Seconds to wait before a batch starts, so messages created close
    // together share one browser cold start; and the batch size cap.
    'lote_espera' => (int) env('WHATSAPP_LOTE_ESPERA', 60),
    'lote_maximo' => (int) env('WHATSAPP_LOTE_MAXIMO', 50),

    // Private directory where the linking bridge writes its state and QR.
    'estado_dir' => storage_path('app/private/whatsapp'),

    // Seconds the linking QR stays valid before the bridge gives up.
    'vinculacion_timeout' => (int) env('WHATSAPP_VINCULACION_TIMEOUT', 180),

    // Daily reminder for the next day's pending turnos (hemocentro local time).
    'recordatorio_hora' => env('WHATSAPP_RECORDATORIO_HORA', '18:00'),
];
