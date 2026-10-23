<?php

namespace App\Services\Whatsapp;

interface WhatsappSender
{
    /**
     * Send one text message. Returns null on success or the failure reason.
     */
    public function send(string $numero, string $mensaje): ?string;
}
