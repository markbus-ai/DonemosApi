<?php

namespace App\Http\Requests\Paciente;

use Illuminate\Foundation\Http\FormRequest;

class StorePacienteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Ajustar tabla si tu migración usa 'paciente' singular
            'dni' => ['required', 'string', 'unique:pacientes,dni'],
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'telefono' => ['required', 'string', 'max:20'],
            'sexo' => ['nullable', 'string', 'in:M,F'],
            'altura' => ['nullable', 'numeric', 'gt:0', 'max:250'],
        ];
    }
}
