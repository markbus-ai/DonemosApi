<?php

namespace App\Rules;

use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\Restriccion;
use App\Models\TipoDonacion;
use Carbon\Carbon;

/**
 * DonationRules - Objeto de dominio puro.
 *
 * Implementa normativa oficial argentina Resolución 536/2026 — solo reglas
 * calculables con historial (fecha + tipo). No inventa aptitud médica.
 *
 * Reglas calculables implementadas:
 *  - PLAQUETAS Intervalo < 48h desde última aféresis -> WARNING_INTERVALO
 *  - PLAQUETAS Frecuencia semanal > 2 en 7 días -> WARNING_LIMITE_PERIODO
 *  - PLAQUETAS Anual > 24 en 12 meses -> WARNING_LIMITE_ANUAL
 *  - PLASMA Intervalo ocasional < 14 días -> WARNING_INTERVALO
 *  - PLASMA Frecuencia > 24 en 12 meses (ocasional) -> WARNING_LIMITE_ANUAL
 *
 * Reglas NO implementables (ver TODOs abajo) quedan a criterio médico / autorización manual.
 *
 * NO crea donaciones, turnos ni autorizaciones.
 * Solo evalúa el HISTORIAL COMPLETO del paciente y acumula warnings estructurados.
 *
 * Retorna: ['allowed' => bool, 'warnings' => [['code','message']]]
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

        $historial = Donacion::where('paciente_id', $pacienteId)
            ->with('tipoDonacion')
            ->orderByDesc('fecha')
            ->get();

        // Sex-aware minimum interval; skipped entirely when sex is unknown.
        $warnings = array_merge($warnings, $this->checkSexInterval($pacienteModel, $historial, $fechaSolicitada));

        $codigoTipo = strtoupper((string) $tipoModel->codigo);

        // -------------------------------------------------
        // PLAQUETAS — Reglas Resolución 536/2026
        // -------------------------------------------------
        if ($codigoTipo === 'PLAQUETAS') {
            // Regla 1: Intervalo mínimo < 48 horas desde última donación por aféresis
            $ultimaPlaquetas = $historial->first(fn ($d) => strtoupper((string) $d->tipoDonacion->codigo) === 'PLAQUETAS');
            if ($ultimaPlaquetas) {
                $fechaUltima = Carbon::parse($ultimaPlaquetas->fecha)->startOfDay();
                $diffHoras = $fechaUltima->diffInHours($fechaSolicitada, false);
                // diffInHours false => signed; si fechaSolicitada es anterior, diff negativo -> no aplica
                if ($diffHoras >= 0 && $diffHoras < 48) {
                    $warnings[] = [
                        'code' => 'WARNING_INTERVALO',
                        'message' => 'PLAQUETAS: intervalo mínimo 48 horas desde la última donación por aféresis no cumplido (Res. 536/2026).',
                    ];
                }
            }

            // Regla 2: Frecuencia semanal > 2 procedimientos en 7 días
            $plaquetasUltimos7Dias = $historial->filter(function ($d) use ($fechaSolicitada) {
                if (strtoupper((string) $d->tipoDonacion->codigo) !== 'PLAQUETAS') {
                    return false;
                }
                $f = Carbon::parse($d->fecha)->startOfDay();

                return $f->greaterThanOrEqualTo($fechaSolicitada->copy()->subDays(7))
                    && $f->lessThan($fechaSolicitada);
            })->count();

            if ($plaquetasUltimos7Dias >= 2) {
                // Ya existen 2 en los últimos 7 días; la solicitada sería la 3ra -> >2 en 7 días
                $warnings[] = [
                    'code' => 'WARNING_LIMITE_PERIODO',
                    'message' => 'PLAQUETAS: supera el límite de 2 procedimientos en 7 días (Res. 536/2026).',
                ];
            }

            // Regla 3: Límite anual > 24 en 12 meses
            $plaquetasUltimoAnio = $historial->filter(function ($d) use ($fechaSolicitada) {
                if (strtoupper((string) $d->tipoDonacion->codigo) !== 'PLAQUETAS') {
                    return false;
                }
                $f = Carbon::parse($d->fecha)->startOfDay();

                return $f->greaterThanOrEqualTo($fechaSolicitada->copy()->subYear())
                    && $f->lessThanOrEqualTo($fechaSolicitada);
                // Nota: lessThanOrEqualTo incluye donaciones del mismo día si existieran;
                // como la solicitada aún no está persistida, no se cuenta a sí misma.
            })->count();

            // Excluir la fecha solicitada si coincidiera (no debería existir), contar solo historial
            // Si ya hay 24 en el último año, la siguiente (25) supera el límite
            if ($plaquetasUltimoAnio >= 24) {
                $warnings[] = [
                    'code' => 'WARNING_LIMITE_ANUAL',
                    'message' => 'PLAQUETAS: supera el límite anual de 24 donaciones en 12 meses (Res. 536/2026).',
                ];
            }
        }

        // -------------------------------------------------
        // PLASMA — Reglas Resolución 536/2026 (donante ocasional)
        // -------------------------------------------------
        if ($codigoTipo === 'PLASMA') {
            // Regla 4: Intervalo general ocasional < 2 semanas (14 días)
            $ultimaPlasma = $historial->first(fn ($d) => strtoupper((string) $d->tipoDonacion->codigo) === 'PLASMA');
            if ($ultimaPlasma) {
                $fechaUltima = Carbon::parse($ultimaPlasma->fecha)->startOfDay();
                $diffDias = $fechaUltima->diffInDays($fechaSolicitada, false);
                if ($diffDias >= 0 && $diffDias < 14) {
                    $warnings[] = [
                        'code' => 'WARNING_INTERVALO',
                        'message' => 'PLASMA ocasional: intervalo mínimo 14 días desde la última donación no cumplido (Res. 536/2026).',
                    ];
                }
            }

            // Regla 5: Frecuencia > 24 donaciones en 12 meses para plasma ocasional
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
                    'code' => 'WARNING_LIMITE_ANUAL',
                    'message' => 'PLASMA ocasional: supera el límite de 24 donaciones en 12 meses (Res. 536/2026).',
                ];
            }
        }

        // -------------------------------------------------
        // TODO: Reglas NO implementables con modelo actual
        // -------------------------------------------------
        // TODO (PLASMA seriado - IgG/proteínas): La Res. 536/2026 prevé para donante
        //       de plasma seriado controles clínicos seriados: IgG con umbrales 0.6 y 0.8
        //       g/dL (suspensión/re-evaluación) + proteínas totales y albúmina, con
        //       frecuencia de controles según intensidad. Estos datos NO existen en el
        //       modelo actual (tablas donaciones/pacientes/turnos). NO hardcodear
        //       thresholds clínicos ni inventar aptitud médica. Esta regla queda a
        //       criterio médico y autorización manual del personal de hemoterapia.
        //       Cuando se incorporen tablas de controles clínicos (ej. plasma_controles
        //       con igg, proteinas, albumina, fecha_control), implementar validación
        //       específica en este mismo método o en un servicio clínico dedicado.

        // TODO (Regla cruzada sangre/doble producto/aféresis sin retorno -> 4 semanas):
        //       La normativa establece 4 semanas de intervalo después de donación de
        //       sangre total / doble producto / aféresis sin retorno antes de
        //       plaquetas/plasma por aféresis. El modelo actual solo registra tipos
        //       PLASMA y PLAQUETAS en tipos_donacion; no existen tipos SANGRE,
        //       DOBLE_PRODUCTO ni AFERESIS_SIN_RETORNO en BD. No se puede evaluar
        //       esta interacción hasta que se amplíe el catálogo de tipos y se
        //       registre ese historial. Dejar como warning manual hasta entonces.
        //       Al agregar esos tipos, implementar aquí: buscar última donación de
        //       esos tipos y validar diff < 28 días para solicitudes PLAQUETAS/PLASMA.

        return [
            'allowed' => empty($warnings),
            'warnings' => $warnings,
        ];
    }

    /**
     * Emit WARNING_INTERVALO_SEXO when the latest donation is closer than the
     * sex-specific minimum interval. Months come from config/donacion_intervalos.php;
     * a patient with unknown (or unconfigured) sex gets no warning — the rule
     * never guesses an interval.
     *
     * @param  \Illuminate\Support\Collection<int, Donacion>  $historial
     * @return array<int, array{code: string, message: string}>
     */
    private function checkSexInterval(?Paciente $paciente, $historial, Carbon $fechaSolicitada): array
    {
        if ($paciente === null || $paciente->sexo === null) {
            return [];
        }

        $minMeses = config('donacion_intervalos.min_por_sexo.'.strtoupper(trim((string) $paciente->sexo)));

        if ($minMeses === null) {
            return [];
        }

        $ultima = $historial->first();

        if ($ultima === null) {
            return [];
        }

        $fechaUltima = Carbon::parse($ultima->fecha)->startOfDay();

        // Solicitudes anteriores a la última donación no aplican a esta regla.
        if ($fechaUltima->greaterThan($fechaSolicitada)) {
            return [];
        }

        $fechaLimite = $fechaUltima->copy()->addMonths((int) $minMeses);

        if ($fechaSolicitada->lessThan($fechaLimite)) {
            return [[
                'code' => 'WARNING_INTERVALO_SEXO',
                'message' => sprintf(
                    'Intervalo mínimo entre donaciones no cumplido: %d meses (%s).',
                    (int) $minMeses,
                    $paciente->sexo,
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
            'code' => 'BLOQUEO_DIFERIMIENTO',
            'message' => 'Paciente con diferimiento activo: la donación no está habilitada (Res. 536/2026).',
        ]];
    }
}
