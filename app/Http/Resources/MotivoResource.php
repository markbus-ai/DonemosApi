<?php

namespace App\Http\Resources;

use App\Models\Motivo;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only deferral/observation reason catalog row for mobile form building.
 *
 * @mixin Motivo
 */
class MotivoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'nombre' => $this->nombre,
            'plazo_meses' => $this->plazo_meses,
        ];
    }
}
