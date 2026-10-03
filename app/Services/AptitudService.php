<?php

namespace App\Services;

use App\Models\Aptitud;
use App\Models\Paciente;
use Illuminate\Support\Facades\DB;

class AptitudService
{
    public function update(Paciente $paciente, array $data): Paciente
    {
        return DB::transaction(function () use ($paciente, $data) {
            $aptitud = Aptitud::where('tipo', $data['tipo'])->firstOrFail();

            if ($data['tipo'] === 'APTO') {
                $paciente->observaciones()->delete();
                $paciente->restriccion()->delete();
            } elseif ($data['tipo'] === 'APTO_OBSERVACION') {
                $paciente->restriccion()->delete();
            } elseif ($data['tipo'] === 'NO_APTO') {
                $paciente->observaciones()->delete();
                $paciente->restriccion()->delete();
            }

            $paciente->update(['aptitud_id' => $aptitud->id]);

            if ($data['tipo'] === 'APTO_OBSERVACION') {
                $paciente->observaciones()->create([
                    'motivo_id' => $data['motivo_id'],
                    'desde' => $data['desde'],
                    'hasta' => $data['hasta'] ?? null,
                ]);
            }

            if ($data['tipo'] === 'NO_APTO') {
                $paciente->restriccion()->create([
                    'motivo_id' => $data['motivo_id'],
                    'desde' => $data['desde'],
                    'hasta' => $data['hasta'] ?? null,
                ]);
            }

            return $paciente->fresh()->load('aptitud');
        });
    }
}
