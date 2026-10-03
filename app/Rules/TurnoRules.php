<?php

namespace App\Rules;

use App\Models\Paciente;
use App\Models\TipoDonacion;
use App\Models\Turno;
use Carbon\Carbon;

/**
 * TurnoRules - Objeto de dominio para evaluación de TURNOS futuros.
 *
 * Delega la validación de intervalos/frecuencia de DONACIONES reales a DonationRules
 * (normativa Res. 536/2026: plaquetas 48h, 2/7d, 24/año; plasma 14d). NO reimplementa thresholds.
 *
 * Además evalúa turnos futuros con estado PENDIENTE del paciente para evitar
 * asignación duplicada. Solo retorna warnings estructurados; el flujo forzar/autorización
 * lo maneja TurnoService.
 *
 * Retorna: ['allowed' => bool, 'warnings' => [['code','message']]]
 */
class TurnoRules
{
    public function __construct(
        private DonationRules $donationRules
    ) {}

    /**
     * @param Paciente|int $paciente
     * @param string $fecha Y-m-d del turno solicitado
     * @param string $hora H:i del turno solicitado (reservado para futura validación de solapamiento)
     * @param int|null $tipoId Si se especifica, evalúa solo ese tipo; si null, evalúa PLASMA y PLAQUETAS y mergea warnings
     * @return array{allowed: bool, warnings: array<int, array{code: string, message: string}>}
     */
    public function check(Paciente|int $paciente, string $fecha, string $hora, ?int $tipoId = null): array
    {
        $pacienteId = $paciente instanceof Paciente ? $paciente->id : $paciente;

        // Active deferral blocks turno booking regardless of the tipo catalog.
        // Applied once here; the same code is stripped from per-tipo results below.
        $warnings = $this->donationRules->checkDeferral($pacienteId, $fecha);

        // -------------------------------------------------
        // 1) Validación de intervalos vía DonationRules (donaciones previas reales)
        // -------------------------------------------------
        if ($tipoId !== null) {
            $result = $this->donationRules->check($pacienteId, $tipoId, $fecha);
            $warnings = array_merge($warnings, $this->withoutDeferral($result['warnings'] ?? []));
        } else {
            // Sin tipo explícito: evaluar conservadoramente con ambos tipos y mergear warnings
            $tipos = TipoDonacion::whereIn('nombre', ['PLASMA', 'PLAQUETAS'])->get();
            foreach ($tipos as $tipo) {
                $result = $this->donationRules->check($pacienteId, $tipo->id, $fecha);
                $warnings = array_merge($warnings, $this->withoutDeferral($result['warnings'] ?? []));
            }
        }

        // -------------------------------------------------
        // 2) Validación de turnos futuros PENDIENTE
        // -------------------------------------------------
        $turnosPendientes = Turno::where('paciente_id', $pacienteId)
            ->where('estado', 'PENDIENTE')
            ->where('fecha', '>=', Carbon::today()->toDateString())
            ->get();

        if ($turnosPendientes->isNotEmpty()) {
            $warnings[] = [
                'code' => 'TURNO_PENDIENTE_EXISTENTE',
                'message' => 'Paciente ya tiene turno pendiente',
            ];
        }

        // Excluye explícitamente estados CANCELADO y ATENDIDO (no se consultan arriba).
        // TODO: Definir si debe validar solapamiento de fecha/hora exacta vs solo existencia.
        // No hardcodear intervalo de turnos no oficial. Si la regla de negocio requiere
        // validar solapamiento exacto (misma fecha+hora) o ventana (ej. mismo día),
        // implementar aquí comparando $fecha/$hora contra $turnosPendientes. Por ahora
        // solo se advierte existencia de cualquier PENDIENTE futuro.

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
            fn (array $warning) => ($warning['code'] ?? null) !== 'BLOQUEO_DIFERIMIENTO'
        ));
    }
}
