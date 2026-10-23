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
        $hoy = now(config('app.business_timezone'));

        return [
            'paciente_id' => ['required', 'integer', 'exists:pacientes,id'],
            'fecha' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.$hoy->toDateString(), 'before_or_equal:'.$hoy->copy()->addYear()->toDateString()],
            'hora' => ['required', 'date_format:H:i'],
            'tipo_id' => ['sometimes', 'integer', 'exists:tipos_donacion,id'],

            // Optional site; the Service resolves payload → staff sede → default.
            'sede_id' => ['sometimes', 'nullable', 'integer', 'exists:sedes,id'],

            // Flujo de autorización extraordinaria (dos pasos)
            'forzar' => ['sometimes', 'boolean'],
            'motivo' => ['nullable', 'string', 'max:500', Rule::requiredIf(fn () => $this->boolean('forzar'))],
        ];
    }
}
