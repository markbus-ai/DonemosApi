<?php

namespace App\Rules;

use App\Enums\TurnoEstado;
use App\Enums\WarningCode;
use App\Models\Paciente;
use App\Models\TipoDonacion;
use App\Models\Turno;
use App\Support\Hoy;

/**
 * TurnoRules - domain checks for a future turno.
 *
 * Interval and frequency rules over real donations are delegated to
 * DonationRules (Res. 536/2026: platelets 48h, 2 per 7 days, 24 per year;
 * plasma 14 days); no threshold is re-implemented here. On top of that it
 * flags a donor who already has a pending future turno. It only returns
 * structured warnings; the `forzar` / authorization flow belongs to
 * TurnoService.
 *
 * Returns: ['allowed' => bool, 'warnings' => [['code', 'message']]]
 */
class TurnoRules
{
    public function __construct(
        private DonationRules $donationRules
    ) {}

    /**
     * @param  string  $fecha  requested turno date (Y-m-d)
     * @param  int|null  $tipoId  evaluate only this type; null evaluates PLASMA and PLAQUETAS and merges their warnings
     * @return array{allowed: bool, warnings: array<int, array{code: string, message: string}>}
     */
    public function check(Paciente|int $paciente, string $fecha, ?int $tipoId = null): array
    {
        $pacienteId = $paciente instanceof Paciente ? $paciente->id : $paciente;

        // Active deferral blocks turno booking regardless of the tipo catalog.
        // Applied once here; the same code is stripped from per-tipo results below.
        $warnings = $this->donationRules->checkDeferral($pacienteId, $fecha);

        // 1) Interval rules over the real donation history, through DonationRules.
        if ($tipoId !== null) {
            $result = $this->donationRules->check($pacienteId, $tipoId, $fecha);
            $warnings = array_merge($warnings, $this->withoutDeferral($result['warnings'] ?? []));
        } else {
            // No explicit type: evaluate conservatively by code and merge the warnings.
            $tipos = TipoDonacion::whereIn('codigo', ['PLASMA', 'PLAQUETAS'])->get();
            foreach ($tipos as $tipo) {
                $result = $this->donationRules->check($pacienteId, $tipo->id, $fecha);
                $warnings = array_merge($warnings, $this->withoutDeferral($result['warnings'] ?? []));
            }
        }

        // 2) Any pending future turno of the donor (attended and cancelled ones
        // do not count). Only its existence is flagged, not a time overlap.
        $tienePendiente = Turno::where('paciente_id', $pacienteId)
            ->where('estado', TurnoEstado::Pendiente->value)
            ->where('fecha', '>=', Hoy::fecha())
            ->exists();

        if ($tienePendiente) {
            $warnings[] = [
                'code' => WarningCode::TurnoPendienteExistente->value,
                'message' => 'Paciente ya tiene turno pendiente',
            ];
        }

        return [
            'allowed' => empty($warnings),
            'warnings' => $warnings,
        ];
    }

    /**
     * Drop the deferral code emitted by DonationRules::check so it is not
     * duplicated; TurnoRules already emitted it once for the whole turno.
     *
     * @param  array<int, array{code: string, message: string}>  $warnings
     * @return array<int, array{code: string, message: string}>
     */
    private function withoutDeferral(array $warnings): array
    {
        return array_values(array_filter(
            $warnings,
            fn (array $warning) => ($warning['code'] ?? null) !== WarningCode::BloqueoDiferimiento->value
        ));
    }
}
