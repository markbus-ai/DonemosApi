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
        ];
    }
}
