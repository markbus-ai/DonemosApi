<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only donation-type catalog row for mobile form building.
 */
class TipoDonacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'es_aferesis' => (bool) $this->es_aferesis,
            'duracion_minutos' => $this->duracion_minutos,
        ];
    }
}
