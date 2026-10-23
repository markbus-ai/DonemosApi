<?php

namespace App\Services;

use App\Models\Observacion;
use App\Models\Paciente;
use App\Support\Hoy;
use Illuminate\Database\Eloquent\ModelNotFoundException;

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

    /**
     * The observation the legacy per-patient removal targets: the most recent
     * active one (not expired as of today), else the most recent overall.
     *
     * @throws ModelNotFoundException when the patient has no observations.
     */
    public function latestFor(Paciente $paciente): Observacion
    {
        return $paciente->observaciones()
            ->orderByRaw('CASE WHEN hasta IS NULL OR hasta >= ? THEN 0 ELSE 1 END', [Hoy::fecha()])
            ->orderByDesc('desde')
            ->orderByDesc('id')
            ->firstOrFail();
    }

    public function delete(Observacion $observacion): void
    {
        $observacion->delete();
    }
}
