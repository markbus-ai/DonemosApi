<?php

namespace App\Services;

use App\Enums\WarningCode;
use App\Models\AutorizacionExtraordinaria;
use App\Support\ForcedAuthor;

/**
 * Audit trail of forced operations (turnos and donaciones booked over rule
 * warnings). The motivo names every overridden rule in words, followed by
 * the operator's justification.
 */
class AutorizacionExtraordinariaService
{
    /**
     * @param  array<int, array{code: string, message?: string}>  $warnings
     */
    public function registrar(int $pacienteId, array $warnings, ?string $motivo): AutorizacionExtraordinaria
    {
        return AutorizacionExtraordinaria::create([
            'paciente_id' => $pacienteId,
            // Fail-closed: only the authenticated staff operator can authorize.
            'usuario_id' => ForcedAuthor::idOrFail(),
            'motivo' => trim(WarningCode::describir($warnings).' — '.($motivo ?? ''), ' —'),
            'fecha' => now(),
        ]);
    }
}
