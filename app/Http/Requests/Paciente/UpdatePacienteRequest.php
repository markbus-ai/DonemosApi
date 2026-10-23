<?php

namespace App\Http\Requests\Paciente;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePacienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['sometimes', 'string', 'max:100'],
            'apellido' => ['sometimes', 'string', 'max:100'],
            'telefono' => ['sometimes', 'string', 'max:20'],
            'sexo' => ['sometimes', 'nullable', 'string', 'in:M,F'],
            'altura' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:250'],
            // Manual cycle counters; staff sets or resets them freely.
            'ciclo_actual' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'serie_actual' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.config('ciclo_donacion.series_por_ciclo')],
            'donacion_actual' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.config('ciclo_donacion.donaciones_por_serie')],
        ];
    }
}
