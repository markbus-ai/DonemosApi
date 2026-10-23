<?php

namespace App\Enums;

/**
 * Rule codes emitted by DonationRules, TurnoRules, ClinicalSignRules, the
 * platelet gate and the booking gates. label() is the human wording stored in
 * the audit trail of a forced (extraordinary) authorization.
 */
enum WarningCode: string
{
    case BloqueoDiferimiento = 'BLOQUEO_DIFERIMIENTO';
    case BloqueoNoApto = 'BLOQUEO_NO_APTO';
    case Intervalo = 'WARNING_INTERVALO';
    case IntervaloSexo = 'WARNING_INTERVALO_SEXO';
    case LimiteAnual = 'WARNING_LIMITE_ANUAL';
    case LimitePeriodo = 'WARNING_LIMITE_PERIODO';
    case TurnoPendienteExistente = 'TURNO_PENDIENTE_EXISTENTE';
    case HemoglobinaBaja = 'HEMOGLOBINA_BAJA';
    case PesoBajo = 'PESO_BAJO';
    case PlaquetasNoHabilitadas = 'PLAQUETAS_NO_HABILITADAS';
    // Hard booking conflicts: never overridable, listed for completeness.
    case HorarioOcupado = 'HORARIO_OCUPADO';
    case CapacidadExcedida = 'CAPACIDAD_EXCEDIDA';

    public function label(): string
    {
        return match ($this) {
            self::BloqueoDiferimiento => 'Diferimiento activo',
            self::BloqueoNoApto => 'Donante NO APTO',
            self::Intervalo => 'Incumplimiento de intervalo mínimo',
            self::IntervaloSexo => 'Incumplimiento de intervalo mínimo por sexo',
            self::LimiteAnual => 'Supera límite anual',
            self::LimitePeriodo => 'Supera límite del período',
            self::TurnoPendienteExistente => 'Paciente ya tiene turno pendiente',
            self::HemoglobinaBaja => 'Hemoglobina baja',
            self::PesoBajo => 'Peso bajo',
            self::PlaquetasNoHabilitadas => 'Plaquetas no habilitadas',
            self::HorarioOcupado => 'Horario ocupado',
            self::CapacidadExcedida => 'Capacidad diaria excedida',
        };
    }

    /** Label for any code; an unknown one (e.g. a configured clinical code) stays as is. */
    public static function labelFor(string $code): string
    {
        return self::tryFrom($code)?->label() ?? $code;
    }

    /**
     * Audit motivo for a set of overridden warnings.
     *
     * @param  array<int, array{code: string, message?: string}>  $warnings
     */
    public static function describir(array $warnings): string
    {
        $codes = array_column($warnings, 'code');

        return $codes === []
            ? 'Autorización extraordinaria'
            : implode(' + ', array_map(self::labelFor(...), $codes));
    }
}
