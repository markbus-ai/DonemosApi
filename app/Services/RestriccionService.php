<?php

namespace App\Services;

use App\Models\Paciente;
use App\Models\Restriccion;
use DomainException;

class RestriccionService
{
    public function create(Paciente $paciente, array $data): Restriccion
    {
        if ($paciente->restriccion()->exists()) {
            throw new DomainException('El paciente ya tiene una restricción.');
        }

        return $paciente->restriccion()->create([
            'motivo_id' => $data['motivo_id'],
            'desde' => $data['desde'],
            'hasta' => $data['hasta'] ?? null,
        ]);
    }

    public function delete(Restriccion $restriccion): void
    {
        $restriccion->delete();
    }
}
