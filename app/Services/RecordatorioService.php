<?php

namespace App\Services;

use App\Jobs\EnviarRecordatorioWhatsApp;
use App\Models\RecordatorioTurno;
use App\Models\Turno;
use App\Support\Hoy;
use App\Support\TelefonoAR;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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
    public const CONFIRMACION = 'CONFIRMACION';

    public const RECORDATORIO = 'RECORDATORIO';

    public const CANCELACION = 'CANCELACION';

    public function __construct(
        private array $config = []
    ) {}

    /**
     * Snapshot the reminder for a turno, persist it PENDIENTE and dispatch the
     * (no-op) delivery job.
     */
    public function create(Turno $turno, string $tipo = self::RECORDATORIO): RecordatorioTurno
    {
        $vigente = $turno->estado === 'PENDIENTE' && $turno->fecha->toDateString() >= Hoy::fecha();

        if ($tipo === self::CANCELACION ? $turno->estado !== 'CANCELADO' : ! $vigente) {
            throw ValidationException::withMessages([
                'turno' => $tipo === self::CANCELACION
                    ? 'Solo se avisa la cancelación de un turno cancelado.'
                    : 'Solo se envían recordatorios de turnos pendientes y futuros.',
            ]);
        }

        $contenido = $this->render($turno, $tipo);

        return DB::transaction(function () use ($turno, $contenido, $tipo): RecordatorioTurno {
            /** @var RecordatorioTurno $record */
            $record = $turno->recordatorios()->create([
                'canal' => $this->config('canal'),
                'tipo' => $tipo,
                'estado' => 'PENDIENTE',
                'contenido' => $contenido,
                'intento' => 1,
            ]);

            // Never send for a booking that is rolled back.
            EnviarRecordatorioWhatsApp::dispatch($record->id)->afterCommit();

            return $record;
        });
    }

    /**
     * Automatic message: at most one per turno and tipo unless the previous
     * one failed. Returns null when it was already scheduled or sent.
     */
    public function programar(Turno $turno, string $tipo): ?RecordatorioTurno
    {
        $existe = $turno->recordatorios()->where('tipo', $tipo)->where('estado', '!=', 'FALLIDO')->exists();

        return $existe ? null : $this->create($turno, $tipo);
    }

    /**
     * WhatsApp number for a message, or the reason it must not be sent. Outside
     * production every message goes to WHATSAPP_NUMERO_PRUEBA when the real
     * driver is on: demo phones are fake and may belong to real people.
     *
     * @return array{0: ?string, 1: ?string} [numero, error]
     */
    public function destino(RecordatorioTurno $record): array
    {
        $numero = TelefonoAR::paraWhatsapp($record->turno?->paciente?->telefono);

        if ($numero === null) {
            return [null, 'Teléfono del donante inválido para WhatsApp.'];
        }

        if (config('whatsapp.driver') === 'whatsplay' && ! app()->isProduction()) {
            $prueba = TelefonoAR::paraWhatsapp(config('whatsapp.numero_prueba'));

            return $prueba === null
                ? [null, 'Fuera de producción hay que definir WHATSAPP_NUMERO_PRUEBA: nunca se envía a teléfonos reales.']
                : [$prueba, null];
        }

        return [$numero, null];
    }

    public function list(Turno $turno): Collection
    {
        return $turno->recordatorios()->orderByDesc('id')->get();
    }

    public function markSent(RecordatorioTurno $record, ?string $destino = null): RecordatorioTurno
    {
        $record->update([
            'estado' => 'ENVIADO',
            'sent_at' => now(),
            'destino' => $destino ?? $record->destino,
            'error' => null,
        ]);

        return $record->fresh();
    }

    public function markFailed(RecordatorioTurno $record, ?string $error = null, ?string $destino = null): RecordatorioTurno
    {
        $record->update([
            'estado' => 'FALLIDO',
            'intento' => $record->intento + 1,
            'error' => $error,
            'destino' => $destino ?? $record->destino,
        ]);

        return $record->fresh();
    }

    /**
     * Render the reminder body from config. Only donor name, turno date/time
     * and sede are exposed; no DNI or clinical data.
     */
    public function render(Turno $turno, string $tipo = self::RECORDATORIO): string
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

        $plantilla = $tipo === self::RECORDATORIO
            ? $this->config('plantilla')
            : (string) config("recordatorio_predonacion.plantillas.{$tipo}");

        return strtr($plantilla, $replacements);
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
