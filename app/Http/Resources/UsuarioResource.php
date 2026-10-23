<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UsuarioResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'rol_id' => $this->rol_id,
            'rol' => $this->rol?->nombre,
            'sede_id' => $this->sede_id,
            'zona_id' => $this->zona_id,
            'puede_ver_reportes' => $this->puedeVerReportes(),
        ];
    }
}
