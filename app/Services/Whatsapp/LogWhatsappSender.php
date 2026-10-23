<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Log;

/**
 * Default driver: writes the message to the log instead of sending it.
 */
class LogWhatsappSender implements WhatsappSender
{
    public function send(string $numero, string $mensaje): ?string
    {
        Log::info('WhatsApp (driver log, no enviado)', ['numero' => $numero, 'mensaje' => $mensaje]);

        return null;
    }
}
