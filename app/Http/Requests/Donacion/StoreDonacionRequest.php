<?php

namespace App\Http\Requests\Donacion;

use Illuminate\Foundation\Http\FormRequest;

class StoreDonacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paciente_id' => ['required', 'integer', 'exists:pacientes,id'],
            'tipo_id' => ['required', 'integer', 'exists:tipos_donacion,id'],
            'fecha' => ['sometimes', 'date'],
            'forzar' => ['sometimes', 'boolean'],
        ];
    }
}
