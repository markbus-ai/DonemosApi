<?php

namespace App\Jobs;

use App\Models\RecordatorioTurno;
use App\Services\RecordatorioService;
use App\Services\Whatsapp\WhatsappSender;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sends every PENDIENTE turno message in batches. WhatsPlay cold-starts a
 * whole browser, so messages are not sent one by one: each new message
 * dispatches this job with a short delay and the job is unique until it
 * starts, so everything queued in that window shares one browser start-up.
 * Batches never overlap (single WhatsApp session); leftovers chain another.
 *
 * Never double-messages: the batch is claimed (ENVIANDO) in a transaction
 * before the sender runs, and only PENDIENTE rows are ever picked. A sender
 * that throws or a job that dies mid-send leaves its rows FALLIDO with the
 * reason; they can be re-sent manually. Single attempt for the same reason.
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
        $records = $this->reclamar();

        $mensajes = [];
        foreach ($records as $record) {
            [$numero, $error] = $service->destino($record);

            if ($numero === null) {
                $service->markFailed($record, $error);

                continue;
            }

            $mensajes[$record->id] = ['numero' => $numero, 'mensaje' => $record->contenido];
        }

        try {
            $resultados = $mensajes === [] ? [] : $sender->sendLote($mensajes);
        } catch (Throwable $e) {
            report($e);
            $resultados = array_fill_keys(array_keys($mensajes), 'Falló el envío por WhatsApp: '.mb_substr($e->getMessage(), 0, 300));
        }

        foreach ($mensajes as $id => $mensaje) {
            $record = $records->find($id);
            $error = array_key_exists($id, $resultados)
                ? $resultados[$id]
                : 'WhatsPlay no informó el resultado de este mensaje.';

            $error === null
                ? $service->markSent($record, $mensaje['numero'])
                : $service->markFailed($record, $error, $mensaje['numero']);
        }

        if ($records->count() === (int) config('whatsapp.lote_maximo')
            && RecordatorioTurno::where('estado', 'PENDIENTE')->exists()) {
            static::dispatch();
        }
    }

    /**
     * The job died after claiming its batch (timeout, worker crash): those
     * rows may or may not have gone out, so fail them instead of re-sending.
     * A job dropped before it ever ran (overlap release over the attempt
     * limit) claimed nothing and must not touch another batch's rows.
     */
    public function failed(?Throwable $exception): void
    {
        if ($exception instanceof MaxAttemptsExceededException && ! $exception instanceof TimeoutExceededException) {
            return;
        }

        RecordatorioTurno::where('estado', 'ENVIANDO')->get()->each(
            fn (RecordatorioTurno $record) => app(RecordatorioService::class)
                ->markFailed($record, 'El envío se interrumpió; verifique en WhatsApp antes de reenviar.'),
        );
    }

    /**
     * Claim the next batch: PENDIENTE rows become ENVIANDO atomically, so no
     * other run (or a re-run of this one) can pick them again.
     *
     * @return Collection<int, RecordatorioTurno>
     */
    private function reclamar(): Collection
    {
        return DB::transaction(function (): Collection {
            $ids = RecordatorioTurno::where('estado', 'PENDIENTE')
                ->orderBy('id')
                ->limit((int) config('whatsapp.lote_maximo'))
                ->lockForUpdate()
                ->pluck('id');

            RecordatorioTurno::whereIn('id', $ids)->where('estado', 'PENDIENTE')->update(['estado' => 'ENVIANDO']);

            return RecordatorioTurno::with('turno.paciente', 'turno.sede')
                ->whereIn('id', $ids)
                ->where('estado', 'ENVIANDO')
                ->orderBy('id')
                ->get();
        });
    }
}
