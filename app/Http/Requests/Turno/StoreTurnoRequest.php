<?php

namespace App\Http\Requests\Turno;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTurnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paciente_id' => ['required', 'integer', 'exists:pacientes,id'],
            'fecha' => ['required', 'date'],
            'hora' => ['required', 'date_format:H:i'],
            'tipo_id' => ['sometimes', 'integer', 'exists:tipos_donacion,id'],

            // Flujo de autorización extraordinaria (dos pasos)
            'forzar' => ['sometimes', 'boolean'],
            'motivo' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn () => $this->boolean('forzar'))],
        ];
    }
}
