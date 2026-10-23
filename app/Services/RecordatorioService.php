<?php

namespace App\Services;

use App\Enums\RecordatorioEstado;
use App\Enums\RecordatorioTipo;
use App\Enums\TurnoEstado;
use App\Jobs\EnviarLoteWhatsApp;
use App\Models\RecordatorioTurno;
use App\Models\Turno;
use App\Support\Hoy;
use App\Support\TelefonoAR;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Pre-donation reminder service.
 *
 * The reminder text is rendered from config and snapshotted into `contenido`
 * at scheduling time. Delivery is batched (see EnviarLoteWhatsApp);
 * this service only records and dispatches. Estado transitions advance the row
 * and bump `intento` on failure.
 */
class RecordatorioService
{
    public function __construct(
        private array $config = []
    ) {}

    /**
     * Snapshot the message for a turno, persist it PENDIENTE and dispatch the
     * batched delivery job.
     */
    public function create(Turno $turno, RecordatorioTipo|string $tipo = RecordatorioTipo::Recordatorio): RecordatorioTurno
    {
        $tipo = RecordatorioTipo::from($tipo instanceof RecordatorioTipo ? $tipo->value : $tipo);
        $vigente = $turno->estado === TurnoEstado::Pendiente->value && $turno->fecha->toDateString() >= Hoy::fecha();

        if ($tipo === RecordatorioTipo::Cancelacion ? $turno->estado !== TurnoEstado::Cancelado->value : ! $vigente) {
            throw ValidationException::withMessages([
                'turno' => $tipo === RecordatorioTipo::Cancelacion
                    ? 'Solo se avisa la cancelación de un turno cancelado.'
                    : 'Solo se envían recordatorios de turnos pendientes y futuros.',
            ]);
        }

        $contenido = $this->render($turno, $tipo);

        return DB::transaction(function () use ($turno, $contenido, $tipo): RecordatorioTurno {
            /** @var RecordatorioTurno $record */
            $record = $turno->recordatorios()->create([
                'canal' => $this->config('canal'),
                'tipo' => $tipo->value,
                'estado' => RecordatorioEstado::Pendiente->value,
                'contenido' => $contenido,
                'intento' => 1,
            ]);

            // Batched send; never for a booking that is rolled back.
            EnviarLoteWhatsApp::programar();

            return $record;
        });
    }

    /**
     * Automatic message: at most one per turno and tipo unless the previous
     * one failed. Returns null when it was already scheduled or sent. The
     * check and the insert run under a per turno+tipo lock so two concurrent
     * callers (booking retry, scheduler overlap) cannot both insert.
     */
    public function programar(Turno $turno, RecordatorioTipo|string $tipo): ?RecordatorioTurno
    {
        $tipo = $tipo instanceof RecordatorioTipo ? $tipo->value : $tipo;

        try {
            return Cache::lock("recordatorio:{$turno->id}:{$tipo}", 10)->block(5, function () use ($turno, $tipo) {
                $existe = $turno->recordatorios()->where('tipo', $tipo)->whereIn('estado', RecordatorioEstado::activos())->exists();

                return $existe ? null : $this->create($turno, $tipo);
            });
        } catch (LockTimeoutException) {
            // Someone else is scheduling this very message right now.
            Log::warning('Recordatorio no programado: lock ocupado', ['turno_id' => $turno->id, 'tipo' => $tipo]);

            return null;
        }
    }

    /**
     * A cancelled turno must not get its confirmation or reminder after the
     * cancellation notice: void whatever is still PENDIENTE. Rows already
     * claimed by a batch (ENVIANDO) can no longer be stopped.
     */
    public function anularPendientes(Turno $turno): int
    {
        return $turno->recordatorios()
            ->whereIn('tipo', [RecordatorioTipo::Confirmacion->value, RecordatorioTipo::Recordatorio->value])
            ->where('estado', RecordatorioEstado::Pendiente->value)
            ->update(['estado' => RecordatorioEstado::Fallido->value, 'error' => 'Turno cancelado antes del envío']);
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
            'estado' => RecordatorioEstado::Enviado->value,
            'sent_at' => now(),
            'destino' => $destino ?? $record->destino,
            'error' => null,
        ]);

        return $record->fresh();
    }

    public function markFailed(RecordatorioTurno $record, ?string $error = null, ?string $destino = null): RecordatorioTurno
    {
        $record->update([
            'estado' => RecordatorioEstado::Fallido->value,
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
    public function render(Turno $turno, RecordatorioTipo|string $tipo = RecordatorioTipo::Recordatorio): string
    {
        $tipo = $tipo instanceof RecordatorioTipo ? $tipo->value : $tipo;
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

        $plantilla = $tipo === RecordatorioTipo::Recordatorio->value
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
