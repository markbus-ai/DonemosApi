<?php

namespace App\Services\Whatsapp;

use Illuminate\Support\Facades\Log;

/**
 * Default driver: writes the messages to the log instead of sending them.
 */
class LogWhatsappSender implements WhatsappSender
{
    public function sendLote(array $mensajes): array
    {
        foreach ($mensajes as $id => $mensaje) {
            Log::info('WhatsApp (driver log, no enviado)', ['id' => $id, ...$mensaje]);
        }

        return array_fill_keys(array_keys($mensajes), null);
    }
}
