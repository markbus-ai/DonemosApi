<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Sanctum tokens expire (config/sanctum.php); drop the dead rows daily.
Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Day-before WhatsApp reminders, at the hemocentro's local time.
Schedule::command('recordatorios:enviar-manana')
    ->dailyAt(config('whatsapp.recordatorio_hora'))
    ->timezone(config('app.business_timezone'));
