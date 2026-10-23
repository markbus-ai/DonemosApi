<?php

namespace App\Rules;

use App\Enums\WarningCode;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\Restriccion;
use App\Models\TipoDonacion;
use Carbon\Carbon;

/**
 * DonationRules - pure domain object.
 *
 * Implements the Argentine regulation Res. 536/2026, only the rules that can
 * be computed from the history (date + type). It never decides medical
 * aptitude.
 *
 * Computable rules implemented:
 *  - PLAQUETAS interval < 48h since the last apheresis -> WARNING_INTERVALO
 *  - PLAQUETAS more than 2 in 7 days -> WARNING_LIMITE_PERIODO
 *  - PLAQUETAS more than 24 in 12 months -> WARNING_LIMITE_ANUAL
 *  - PLASMA (occasional donor) interval < 14 days -> WARNING_INTERVALO
 *  - PLASMA (occasional donor) more than 24 in 12 months -> WARNING_LIMITE_ANUAL
 * plus the active deferral, NO_APTO and sex-aware interval checks.
 *
 * Rules that cannot be computed yet (see the notes at the end of check())
 * are left to medical judgement and the manual authorization.
 *
 * It never creates donations, turnos or authorizations: it evaluates the
 * donor's FULL history and accumulates structured warnings.
 *
 * Returns: ['allowed' => bool, 'warnings' => [['code','message']]]
 */
class DonationRules
{
    /**
     * @return array{allowed: bool, warnings: array<int, array{code: string, message: string}>}
     */
    public function check(Paciente|int $paciente, TipoDonacion|int $tipo, string $fecha): array
    {
        $pacienteId = $paciente instanceof Paciente ? $paciente->id : $paciente;
        $tipoId = $tipo instanceof TipoDonacion ? $tipo->id : $tipo;
        $tipoModel = $tipo instanceof TipoDonacion ? $tipo : TipoDonacion::findOrFail($tipoId);

        $fechaSolicitada = Carbon::parse($fecha)->startOfDay();

        // An active deferral blocks every donation type, regardless of history.
        $warnings = $this->checkDeferral($pacienteId, $fechaSolicitada->toDateString());

        $pacienteModel = $paciente instanceof Paciente ? $paciente : Paciente::find($pacienteId);

        // A donor marked NO_APTO needs an explicit, audited override.
        if (strtoupper((string) $pacienteModel?->aptitud?->tipo) === 'NO_APTO') {
            $warnings[] = [
                'code' => WarningCode::BloqueoNoApto->value,
                'message' => 'Paciente NO APTO: la donación requiere autorización extraordinaria.',
            ];
        }

        $historial = Donacion::where('paciente_id', $pacienteId)
            ->with('tipoDonacion')
            ->orderByDesc('fecha')
            ->get();

        // Sex-aware minimum interval; unknown sex uses the strictest one.
        $warnings = array_merge($warnings, $this->checkSexInterval($pacienteModel, $historial, $fechaSolicitada));

        $codigoTipo = strtoupper((string) $tipoModel->codigo);

        // PLAQUETAS rules (Res. 536/2026).
        if ($codigoTipo === 'PLAQUETAS') {
            // Rule 1: minimum 48 hours since the last apheresis donation.
            $ultimaPlaquetas = $historial->first(fn ($d) => strtoupper((string) $d->tipoDonacion->codigo) === 'PLAQUETAS');
            if ($ultimaPlaquetas) {
                $fechaUltima = Carbon::parse($ultimaPlaquetas->fecha)->startOfDay();
                $diffHoras = $fechaUltima->diffInHours($fechaSolicitada, false);
                // Signed diff: a requested date before the last one is negative and does not apply.
                if ($diffHoras >= 0 && $diffHoras < 48) {
                    $warnings[] = [
                        'code' => WarningCode::Intervalo->value,
                        'message' => 'PLAQUETAS: intervalo mínimo 48 horas desde la última donación por aféresis no cumplido (Res. 536/2026).',
                    ];
                }
            }

            // Rule 2: at most 2 procedures in 7 days.
            $plaquetasUltimos7Dias = $historial->filter(function ($d) use ($fechaSolicitada) {
                if (strtoupper((string) $d->tipoDonacion->codigo) !== 'PLAQUETAS') {
                    return false;
                }
                $f = Carbon::parse($d->fecha)->startOfDay();

                return $f->greaterThanOrEqualTo($fechaSolicitada->copy()->subDays(7))
                    && $f->lessThan($fechaSolicitada);
            })->count();

            if ($plaquetasUltimos7Dias >= 2) {
                // Two already in the last 7 days: the requested one would be the third.
                $warnings[] = [
                    'code' => WarningCode::LimitePeriodo->value,
                    'message' => 'PLAQUETAS: supera el límite de 2 procedimientos en 7 días (Res. 536/2026).',
                ];
            }

            // Rule 3: at most 24 in 12 months.
            $plaquetasUltimoAnio = $historial->filter(function ($d) use ($fechaSolicitada) {
                if (strtoupper((string) $d->tipoDonacion->codigo) !== 'PLAQUETAS') {
                    return false;
                }
                $f = Carbon::parse($d->fecha)->startOfDay();

                return $f->greaterThanOrEqualTo($fechaSolicitada->copy()->subYear())
                    && $f->lessThanOrEqualTo($fechaSolicitada);
                // Same-day donations count; the requested one is not persisted
                // yet, so it never counts itself.
            })->count();

            // With 24 in the last year, the next one (25th) exceeds the limit.
            if ($plaquetasUltimoAnio >= 24) {
                $warnings[] = [
                    'code' => WarningCode::LimiteAnual->value,
                    'message' => 'PLAQUETAS: supera el límite anual de 24 donaciones en 12 meses (Res. 536/2026).',
                ];
            }
        }

        // PLASMA rules for the occasional donor (Res. 536/2026).
        if ($codigoTipo === 'PLASMA') {
            // Rule 4: minimum 14 days between occasional plasma donations.
            $ultimaPlasma = $historial->first(fn ($d) => strtoupper((string) $d->tipoDonacion->codigo) === 'PLASMA');
            if ($ultimaPlasma) {
                $fechaUltima = Carbon::parse($ultimaPlasma->fecha)->startOfDay();
                $diffDias = $fechaUltima->diffInDays($fechaSolicitada, false);
                if ($diffDias >= 0 && $diffDias < 14) {
                    $warnings[] = [
                        'code' => WarningCode::Intervalo->value,
                        'message' => 'PLASMA ocasional: intervalo mínimo 14 días desde la última donación no cumplido (Res. 536/2026).',
                    ];
                }
            }

            // Rule 5: at most 24 occasional plasma donations in 12 months.
            $plasmaUltimoAnio = $historial->filter(function ($d) use ($fechaSolicitada) {
                if (strtoupper((string) $d->tipoDonacion->codigo) !== 'PLASMA') {
                    return false;
                }
                $f = Carbon::parse($d->fecha)->startOfDay();

                return $f->greaterThanOrEqualTo($fechaSolicitada->copy()->subYear())
                    && $f->lessThanOrEqualTo($fechaSolicitada);
            })->count();

            if ($plasmaUltimoAnio >= 24) {
                $warnings[] = [
                    'code' => WarningCode::LimiteAnual->value,
                    'message' => 'PLASMA ocasional: supera el límite de 24 donaciones en 12 meses (Res. 536/2026).',
                ];
            }
        }

        // Not computable with the current model (left to medical judgement and
        // the manual authorization):
        // - Serial plasma donors: Res. 536/2026 requires serial IgG (0.6 and
        //   0.8 g/dL thresholds), total protein and albumin controls. Those
        //   values are not stored; never hardcode clinical thresholds. Once a
        //   controls table exists (igg, proteinas, albumina, fecha_control),
        //   validate it here or in a dedicated clinical service.
        // - Cross rule: 4 weeks after whole blood / double product / apheresis
        //   without return before platelet or plasma apheresis. Those types
        //   are not distinguished in the catalog yet; when they are, look up
        //   the last donation of those types and require at least 28 days.

        return [
            'allowed' => empty($warnings),
            'warnings' => $warnings,
        ];
    }

    /**
     * Emit WARNING_INTERVALO_SEXO when the latest donation is closer than the
     * sex-specific minimum interval. Months come from config/donacion_intervalos.php;
     * a patient with unknown (or unconfigured) sex gets the strictest configured
     * interval. The interval is checked against the closest donation on either
     * side of the requested date, so back-dated entries cannot bypass it.
     *
     * @param  \Illuminate\Support\Collection<int, Donacion>  $historial
     * @return array<int, array{code: string, message: string}>
     */
    private function checkSexInterval(?Paciente $paciente, $historial, Carbon $fechaSolicitada): array
    {
        if ($paciente === null) {
            return [];
        }

        $porSexo = (array) config('donacion_intervalos.min_por_sexo');
        $minMeses = $porSexo[strtoupper(trim((string) $paciente->sexo))] ?? ($porSexo === [] ? null : max($porSexo));

        if ($minMeses === null) {
            return [];
        }

        $conflicto = $historial->contains(function ($donacion) use ($fechaSolicitada, $minMeses) {
            $fecha = Carbon::parse($donacion->fecha)->startOfDay();

            return $fechaSolicitada->lessThan($fecha->copy()->addMonths((int) $minMeses))
                && $fecha->lessThan($fechaSolicitada->copy()->addMonths((int) $minMeses));
        });

        if ($conflicto) {
            return [[
                'code' => WarningCode::IntervaloSexo->value,
                'message' => sprintf(
                    'Intervalo mínimo entre donaciones no cumplido: %d meses (%s).',
                    (int) $minMeses,
                    $paciente->sexo ?? 'sexo sin dato',
                ),
            ]];
        }

        return [];
    }

    /**
     * Emit BLOQUEO_DIFERIMIENTO when the patient has an active deferral at $fecha.
     *
     * Public so TurnoRules can apply the block once, outside the donation-type loop.
     *
     * @return array<int, array{code: string, message: string}>
     */
    public function checkDeferral(Paciente|int $paciente, string $fecha): array
    {
        $pacienteId = $paciente instanceof Paciente ? $paciente->id : $paciente;
        $dia = Carbon::parse($fecha)->startOfDay()->toDateString();

        $tieneDiferimientoActivo = Restriccion::where('paciente_id', $pacienteId)
            ->vigente($dia)
            ->exists();

        if (! $tieneDiferimientoActivo) {
            return [];
        }

        return [[
            'code' => WarningCode::BloqueoDiferimiento->value,
            'message' => 'Paciente con diferimiento activo: la donación no está habilitada (Res. 536/2026).',
        ]];
    }
}
