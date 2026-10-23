<?php

namespace App\Console\Commands;

use App\Models\Turno;
use App\Services\RecordatorioService;
use App\Support\Hoy;
use Illuminate\Console\Command;

/**
 * Schedules the day-before WhatsApp reminder for every pending turno of
 * tomorrow (hemocentro calendar). Idempotent: a turno already reminded is
 * skipped, so re-running the command never double-messages.
 */
class EnviarRecordatoriosManana extends Command
{
    protected $signature = 'recordatorios:enviar-manana';

    protected $description = 'Programa por WhatsApp el recordatorio de los turnos pendientes de mañana';

    public function handle(RecordatorioService $recordatorios): int
    {
        $manana = Hoy::carbon()->addDay()->toDateString();
        $programados = 0;

        Turno::with(['paciente', 'sede'])
            ->where('fecha', $manana)
            ->where('estado', 'PENDIENTE')
            ->each(function (Turno $turno) use ($recordatorios, &$programados) {
                $programados += $recordatorios->programar($turno, RecordatorioService::RECORDATORIO) ? 1 : 0;
            });

        $this->info("Recordatorios programados para {$manana}: {$programados}");

        return self::SUCCESS;
    }
}
