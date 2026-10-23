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
            // Argentine DNI: 7-8 digits, no dots and no leading zero, so one
            // person cannot be registered twice under different spellings.
            'dni' => ['required', 'string', 'regex:/^[1-9][0-9]{6,7}$/', 'unique:pacientes,dni'],
            'nombre' => ['required', 'string', 'max:100'],
            'apellido' => ['required', 'string', 'max:100'],
            'telefono' => ['required', 'string', 'max:20', 'regex:/^\+?[0-9 ()-]{6,20}$/'],
            'sexo' => ['nullable', 'string', 'in:M,F'],
            'altura' => ['nullable', 'numeric', 'gt:0', 'max:250'],
        ];
    }
}
