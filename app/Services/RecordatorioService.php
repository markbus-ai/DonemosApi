<?php

namespace App\Services;

use App\Jobs\EnviarRecordatorioWhatsApp;
use App\Models\RecordatorioTurno;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Pre-donation reminder service.
 *
 * The reminder text is rendered from config and snapshotted into `contenido`
 * at scheduling time. Delivery is a queued no-op (see EnviarRecordatorioWhatsApp);
 * this service only records and dispatches. Estado transitions advance the row
 * and bump `intento` on failure.
 */
class RecordatorioService
{
    public function __construct(
        private array $config = []
    ) {}

    /**
     * Snapshot the reminder for a turno, persist it PENDIENTE and dispatch the
     * (no-op) delivery job.
     */
    public function create(Turno $turno): RecordatorioTurno
    {
        $contenido = $this->render($turno);

        return DB::transaction(function () use ($turno, $contenido): RecordatorioTurno {
            /** @var RecordatorioTurno $record */
            $record = $turno->recordatorios()->create([
                'canal' => $this->config('canal'),
                'estado' => 'PENDIENTE',
                'contenido' => $contenido,
                'intento' => 1,
            ]);

            EnviarRecordatorioWhatsApp::dispatch($record->id);

            return $record;
        });
    }

    public function list(Turno $turno): Collection
    {
        return $turno->recordatorios()->orderByDesc('id')->get();
    }

    public function markSent(RecordatorioTurno $record): RecordatorioTurno
    {
        $record->update([
            'estado' => 'ENVIADO',
            'sent_at' => now(),
        ]);

        return $record->fresh();
    }

    public function markFailed(RecordatorioTurno $record): RecordatorioTurno
    {
        $record->update([
            'estado' => 'FALLIDO',
            'intento' => $record->intento + 1,
        ]);

        return $record->fresh();
    }

    /**
     * Render the reminder body from config. Only donor name, turno date/time
     * and sede are exposed; no DNI or clinical data.
     */
    public function render(Turno $turno): string
    {
        $paciente = $turno->paciente;
        $sede = $turno->sede;

        $replacements = [
            ':nombre' => trim(($paciente?->nombre ?? '').' '.($paciente?->apellido ?? '')),
            ':fecha' => $turno->fecha instanceof \DateTimeInterface
                ? $turno->fecha->format('d/m/Y')
                : (string) $turno->fecha,
            ':hora' => $turno->hora instanceof \DateTimeInterface
                ? $turno->hora->format('H:i')
                : substr((string) $turno->hora, 0, 5),
            ':sede' => (string) ($sede?->nombre ?? ''),
        ];

        return strtr($this->config('plantilla'), $replacements);
    }

    /**
     * Config fallback: explicit constructor array wins, otherwise config().
     */
    private function config(string $key): string
    {
        return (string) ($this->config[$key]
            ?? config("recordatorio_predonacion.{$key}"));
    }
}
