<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TurnoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // Keep the raw foreign keys so existing clients do not break.
            'paciente_id' => $this->paciente_id,
            'sede_id' => $this->sede_id,
            'tipo_id' => $this->tipo_id,
            'fecha' => $this->fecha,
            'hora' => $this->hora,
            'estado' => $this->estado,
            'paciente' => $this->whenLoaded('paciente', fn () => $this->paciente === null ? null : [
                'id' => $this->paciente->id,
                'dni' => $this->paciente->dni,
                'nombre' => $this->paciente->nombre,
                'apellido' => $this->paciente->apellido,
            ]),
            'sede' => $this->whenLoaded('sede', fn () => $this->sede === null ? null : [
                'id' => $this->sede->id,
                'nombre' => $this->sede->nombre,
            ]),
            // Legacy rows may have a null tipo_id; serialize as null, never drop the key.
            'tipo' => $this->whenLoaded('tipoDonacion', fn () => $this->tipoDonacion === null ? null : [
                'id' => $this->tipoDonacion->id,
                'codigo' => $this->tipoDonacion->codigo,
                'nombre' => $this->tipoDonacion->nombre,
            ]),
        ];
    }
}
