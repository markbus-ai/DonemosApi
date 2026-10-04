<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe patient payload for list/read endpoints. Clinical data (restricciones
 * history, donaciones, observaciones) is intentionally excluded; only the
 * aptitud label and whether an active deferral exists are exposed.
 */
class PacienteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'dni' => $this->dni,
            'nombre' => $this->nombre,
            'apellido' => $this->apellido,
            'telefono' => $this->telefono,
            'sexo' => $this->sexo,
            'altura' => $this->altura,
            'aptitud' => $this->whenLoaded('aptitud', fn () => $this->aptitud === null ? null : [
                'id' => $this->aptitud->id,
                'tipo' => $this->aptitud->tipo,
            ]),
            // Boolean flag only; never expose the deferral motivo or dates.
            'activeDeferral' => $this->when(
                $this->resource->relationLoaded('activeDeferral'),
                fn () => $this->resource->getRelation('activeDeferral') !== null,
            ),
        ];
    }
}
