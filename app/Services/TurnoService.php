<?php

namespace App\Services;

use App\Enums\RecordatorioTipo;
use App\Enums\TurnoEstado;
use App\Enums\WarningCode;
use App\Exceptions\RuleWarningsException;
use App\Models\Sede;
use App\Models\Turno;
use App\Rules\CapacidadRules;
use App\Rules\TurnoRules;
use App\Support\RowLock;
use App\Support\SedeOperativa;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TurnoService
{
    public function __construct(
        private TurnoRules $turnoRules,
        private CapacidadRules $capacidadRules,
        private RecordatorioService $recordatorios,
        private AutorizacionExtraordinariaService $autorizaciones
    ) {}

    public function list(array $filters = []): Collection
    {
        return Turno::query()
            ->with(['paciente', 'sede', 'tipoDonacion'])
            ->when(isset($filters['paciente_id']), fn ($q) => $q->where('paciente_id', $filters['paciente_id']))
            ->when(isset($filters['fecha']), fn ($q) => $q->where('fecha', $filters['fecha']))
            ->when(isset($filters['desde']), fn ($q) => $q->where('fecha', '>=', $filters['desde']))
            ->orderBy('fecha')
            ->orderBy('hora')
            ->get();
    }

    /**
     * Book a turno. Rule warnings (TurnoRules: donation intervals through
     * DonationRules plus existing pending turnos) stop the booking unless
     * `forzar` is set, in which case the turno and its audited authorization
     * are written together. Capacity and a taken slot are never overridable.
     *
     * With `tipo_id` only that type is evaluated; without it PLASMA and
     * PLAQUETAS are evaluated and their warnings merged.
     *
     * @throws RuleWarningsException rendered as the 409 envelope.
     */
    public function create(array $data): Turno
    {
        // Resolve the sede up front: the capacity gate needs it, and it must be
        // pinned into the payload so the row and the sum agree on the site.
        $sedeId = SedeOperativa::resolver($data)->id;

        try {
            $turno = DB::transaction(function () use ($data, $sedeId) {
                // Serialise bookings per sede so two requests cannot both pass the capacity check.
                RowLock::lock(Sede::class, $sedeId);

                return $this->book($data, $sedeId);
            });
        } catch (UniqueConstraintViolationException) {
            // Concurrent booking of the same slot lost the race.
            throw $this->horarioOcupado();
        }

        $this->recordatorios->programar($turno, RecordatorioTipo::Confirmacion);

        return $turno;
    }

    /**
     * Capacity, slot and rule checks plus the insert; runs under the sede lock.
     */
    private function book(array $data, int $sedeId): Turno
    {
        // Capacity is a blocking hard limit: it preempts the warnings path so
        // `forzar` can never bypass it.
        $capacidad = $this->capacidadRules->check(
            $sedeId,
            $data['fecha'],
            $data['tipo_id'] ?? null,
        );

        if (! $capacidad['allowed']) {
            throw $this->capacidadExcedida($capacidad);
        }

        // A taken slot is a hard conflict (unique index), never overridable;
        // a cancelled turno frees its slot.
        $activo = fn () => Turno::where('fecha', $data['fecha'])->where('hora', $data['hora'])->where('estado', '!=', TurnoEstado::Cancelado->value);
        $slotTomado = $activo()->where('sede_id', $sedeId)->exists()
            || $activo()->where('paciente_id', $data['paciente_id'])->exists();

        if ($slotTomado) {
            throw $this->horarioOcupado();
        }

        $warnings = $this->resolveWarnings($data);

        if (!empty($warnings) && empty($data['forzar'])) {
            throw RuleWarningsException::turno($warnings);
        }

        return $this->persist($data, $warnings, $sedeId);
    }

    /** Insert the turno and, when it was forced over warnings, its audit row. */
    private function persist(array $data, array $warnings, int $sedeId): Turno
    {
        $turno = Turno::create([
            'paciente_id' => $data['paciente_id'],
            'sede_id' => $sedeId,
            'tipo_id' => $data['tipo_id'] ?? null,
            'fecha' => $data['fecha'],
            'hora' => $data['hora'],
            'estado' => TurnoEstado::Pendiente->value,
        ]);

        if ($warnings !== []) {
            $this->autorizaciones->registrar((int) $data['paciente_id'], $warnings, $data['motivo'] ?? null);
        }

        return $turno;
    }

    public function update(Turno $turno, array $data): Turno
    {
        // Only a pending turno can be resolved; attended/cancelled are final.
        if (isset($data['estado']) && ! TurnoEstado::from($turno->estado)->puedePasarA(TurnoEstado::from($data['estado']))) {
            throw ValidationException::withMessages([
                'estado' => sprintf('Un turno %s no puede pasar a %s.', $turno->estado, $data['estado']),
            ]);
        }

        $cancelado = ($data['estado'] ?? null) === TurnoEstado::Cancelado->value && $turno->estado !== TurnoEstado::Cancelado->value;

        $turno->update($data);

        if ($cancelado) {
            $this->recordatorios->anularPendientes($turno);
            $this->recordatorios->programar($turno->fresh(), RecordatorioTipo::Cancelacion);
        }

        return $turno->fresh();
    }

    private function horarioOcupado(): RuleWarningsException
    {
        return RuleWarningsException::turnoBloqueado(
            WarningCode::HorarioOcupado->value,
            'Ese horario ya está ocupado. Elegí otro horario.',
        );
    }

    /**
     * The capacity gate is not overridable, so `forzar` is intentionally
     * absent from the message.
     *
     * @param  array{used: int, limite: int, candidate: int, available: int}  $capacidad
     */
    private function capacidadExcedida(array $capacidad): RuleWarningsException
    {
        return RuleWarningsException::turnoBloqueado(WarningCode::CapacidadExcedida->value, sprintf(
            'CAPACIDAD_EXCEDIDA: la sede alcanzó el límite diario (%d min); turno de %d min, %d min disponibles.',
            $capacidad['limite'],
            $capacidad['candidate'],
            $capacidad['available'],
        ));
    }

    /**
     * Rule warnings from TurnoRules (donation intervals plus pending turnos).
     *
     * @return array<int, array{code: string, message: string}>
     */
    private function resolveWarnings(array $data): array
    {
        $result = $this->turnoRules->check(
            $data['paciente_id'],
            $data['fecha'],
            $data['tipo_id'] ?? null
        );

        return $result['warnings'] ?? [];
    }
}
