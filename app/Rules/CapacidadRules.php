<?php

namespace App\Rules;

use App\Enums\TurnoEstado;
use App\Enums\WarningCode;
use App\Models\TipoDonacion;
use App\Models\Turno;

/**
 * CapacidadRules - duration-sum capacity for one sede+date.
 *
 * A turno occupies `max(duration, duracion_minima)` minutes; a legacy turno
 * without a type (or a type without a duration) occupies `duracion_minima`.
 * A candidate is rejected when `used + candidate > limite`; booking exactly
 * up to the limit is allowed.
 *
 * This class queries the `turnos` table (TurnoRules precedent) because it must
 * sum existing rows; it never persists.
 */
class CapacidadRules
{
    private int $minutosPorDia;

    private int $duracionMinima;

    /**
     * @param  array{minutos_por_dia?: int, duracion_minima?: int}|null  $config  explicit config wins; otherwise config('agenda_capacidad')
     */
    public function __construct(?array $config = null)
    {
        $config ??= config('agenda_capacidad', []);

        $this->minutosPorDia = (int) ($config['minutos_por_dia'] ?? 480);
        $this->duracionMinima = (int) ($config['duracion_minima'] ?? 15);
    }

    /**
     * Evaluate whether a candidate turno fits in the remaining daily capacity.
     *
     * @return array{allowed: bool, used: int, limite: int, candidate: int, available: int, code: ?string}
     */
    public function check(int $sedeId, string $fecha, ?int $tipoId): array
    {
        $used = $this->usedMinutes($sedeId, $fecha);
        $candidate = $this->candidateMinutes($tipoId);
        $allowed = ($used + $candidate) <= $this->minutosPorDia;

        return [
            'allowed' => $allowed,
            'used' => $used,
            'limite' => $this->minutosPorDia,
            'candidate' => $candidate,
            'available' => max(0, $this->minutosPorDia - $used),
            'code' => $allowed ? null : WarningCode::CapacidadExcedida->value,
        ];
    }

    /**
     * Candidate minutes: the type duration, but never below `duracion_minima`.
     * A missing type falls back to `duracion_minima`.
     */
    public function candidateMinutes(?int $tipoId): int
    {
        $duracion = $tipoId === null ? null : TipoDonacion::whereKey($tipoId)->value('duracion_minutos');

        return max((int) ($duracion ?? 0), $this->duracionMinima);
    }

    /**
     * Sum of minutes already booked for the sede+date. Each row's minutes are
     * its type duration (or `duracion_minima` when the type or duration is
     * missing).
     */
    public function usedMinutes(int $sedeId, string $fecha): int
    {
        $turnos = Turno::query()
            ->where('sede_id', $sedeId)
            ->where('fecha', $fecha)
            // A cancelled turno frees its minutes.
            ->where('estado', '!=', TurnoEstado::Cancelado->value)
            ->with('tipoDonacion')
            ->get();

        return $turnos->sum(
            fn (Turno $turno): int => max((int) ($turno->tipoDonacion?->duracion_minutos ?? 0), $this->duracionMinima)
        );
    }
}
