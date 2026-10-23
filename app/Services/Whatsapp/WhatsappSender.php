<?php

namespace App\Services\Whatsapp;

interface WhatsappSender
{
    /**
     * Send a batch of text messages in one go (one WhatsApp Web start-up).
     *
     * @param  array<int, array{numero: string, mensaje: string}>  $mensajes  keyed by message id
     * @return array<int, ?string> failure reason per id, null when sent
     */
    public function sendLote(array $mensajes): array;
}
