<?php

namespace App\Http\Resources;

use App\Models\Donacion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Donation payload for the desktop list endpoint. Embeds the paciente, sede,
 * tipo, componentes (with their tipo) and autoexclusion relations so clients
 * never do N+1 lookups. `presion_arterial` and `descartada` come from the
 * model accessors and rely on the relations being eager-loaded.
 *
 * @mixin Donacion
 */
class DonacionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'numero_donacion' => $this->numero_donacion,
            // Staff-entered cycle metadata; `donacion` is the ordinal within
            // the serie, not the traceability code.
            'ciclo' => $this->ciclo,
            'serie' => $this->serie,
            'donacion' => $this->donacion,
            'fecha' => $this->fecha,
            'tipo_bolsa' => $this->tipo_bolsa,
            'anticoagulante' => $this->anticoagulante,
            'lote' => $this->lote,
            'tubuladura' => $this->tubuladura,
            'brazo' => $this->brazo,
            'dificultad' => $this->dificultad,
            'doble_etiqueta' => $this->doble_etiqueta,
            'operador_id' => $this->operador_id,
            'hemoglobina' => $this->hemoglobina,
            'hematocrito' => $this->hematocrito,
            'plaquetas' => $this->plaquetas,
            // Derived by the model accessor; a loaded relation keeps it query-free.
            'presion_arterial' => $this->presion_arterial,
            'frecuencia_cardiaca' => $this->frecuencia_cardiaca,
            'peso_donante' => $this->peso_donante,
            // Derived discard state; never recomputed here.
            'descartada' => $this->descartada,
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
            'tipo' => $this->whenLoaded('tipoDonacion', fn () => $this->tipoDonacion === null ? null : [
                'id' => $this->tipoDonacion->id,
                'codigo' => $this->tipoDonacion->codigo,
                'nombre' => $this->tipoDonacion->nombre,
            ]),
            'componentes' => $this->whenLoaded('componentes', fn () => $this->componentes->map(fn ($componente) => [
                'id' => $componente->id,
                'tipo' => $componente->tipoDonacion === null ? null : [
                    'id' => $componente->tipoDonacion->id,
                    'codigo' => $componente->tipoDonacion->codigo,
                    'nombre' => $componente->tipoDonacion->nombre,
                ],
                'vencimiento' => $componente->vencimiento,
                'peso' => $componente->peso,
                'descartado' => $componente->descartado,
                'motivo_descarte' => $componente->motivo_descarte,
            ])->all()),
            'autoexclusion' => $this->whenLoaded('autoexclusion', fn () => $this->autoexclusion === null ? null : [
                'id' => $this->autoexclusion->id,
                'motivo' => $this->autoexclusion->motivo,
            ]),
        ];
    }
}
