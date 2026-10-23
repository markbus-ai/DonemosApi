<?php

namespace App\Jobs;

use App\Models\RecordatorioTurno;
use App\Services\RecordatorioService;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends every PENDIENTE turno message in batches. WhatsPlay cold-starts a
 * whole browser, so messages are not sent one by one: each new message
 * dispatches this job with a short delay and the job is unique until it
 * starts, so everything queued in that window shares one browser start-up.
 * Batches never overlap (single WhatsApp session); leftovers chain another.
 * Single attempt: failures are recorded with their reason and can be re-sent
 * manually, so a flaky run never double-messages.
 */
class EnviarLoteWhatsApp implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('whatsplay'))->releaseAfter(30)->expireAfter(1800)];
    }

    public static function programar(): void
    {
        static::dispatch()->delay(now()->addSeconds((int) config('whatsapp.lote_espera')))->afterCommit();
    }

    public function handle(WhatsappSender $sender, RecordatorioService $service): void
    {
        $records = RecordatorioTurno::with('turno.paciente', 'turno.sede')
            ->where('estado', 'PENDIENTE')
            ->orderBy('id')
            ->limit((int) config('whatsapp.lote_maximo'))
            ->get();

        $mensajes = [];
        foreach ($records as $record) {
            [$numero, $error] = $service->destino($record);

            if ($numero === null) {
                $service->markFailed($record, $error);

                continue;
            }

            $mensajes[$record->id] = ['numero' => $numero, 'mensaje' => $record->contenido];
        }

        foreach ($sender->sendLote($mensajes) as $id => $error) {
            $record = $records->firstWhere('id', $id);
            $error === null
                ? $service->markSent($record, $mensajes[$id]['numero'])
                : $service->markFailed($record, $error, $mensajes[$id]['numero']);
        }

        if ($records->count() === (int) config('whatsapp.lote_maximo')
            && RecordatorioTurno::where('estado', 'PENDIENTE')->exists()) {
            static::dispatch();
        }
    }
}
