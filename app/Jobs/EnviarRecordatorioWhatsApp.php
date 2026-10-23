<?php

namespace App\Jobs;

use App\Models\RecordatorioTurno;
use App\Services\RecordatorioService;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Delivers one turno message through the configured WhatsApp driver (`log` by
 * default, `whatsplay` for real sends). One send at a time: WhatsPlay drives a
 * single WhatsApp Web session. Single attempt; a failure is recorded with its
 * reason and can be re-sent manually, so a flaky send never double-messages.
 */
class EnviarRecordatorioWhatsApp implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(
        public int $recordatorioId
    ) {}

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('whatsplay'))->releaseAfter(30)->expireAfter(600)];
    }

    public function handle(WhatsappSender $sender, RecordatorioService $service): void
    {
        $record = RecordatorioTurno::with('turno.paciente')->find($this->recordatorioId);

        if ($record === null || $record->estado !== 'PENDIENTE') {
            return;
        }

        [$numero, $error] = $service->destino($record);

        if ($numero === null) {
            $service->markFailed($record, $error);

            return;
        }

        $error = $sender->send($numero, $record->contenido);

        $error === null
            ? $service->markSent($record, $numero)
            : $service->markFailed($record, $error, $numero);
    }
}
