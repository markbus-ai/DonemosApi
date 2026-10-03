<?php

namespace App\Services;

use App\Models\Observacion;
use App\Models\Paciente;

class ObservacionService
{
    public function create(Paciente $paciente, array $data): Observacion
    {
        return $paciente->observaciones()->create([
            'motivo_id' => $data['motivo_id'],
            'desde' => $data['desde'],
            'hasta' => $data['hasta'] ?? null,
        ]);
    }

    public function delete(Observacion $observacion): void
    {
        $observacion->delete();
    }
}
