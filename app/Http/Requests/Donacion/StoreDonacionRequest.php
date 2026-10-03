<?php

namespace App\Http\Requests\Donacion;

use Illuminate\Foundation\Http\FormRequest;

class StoreDonacionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept both legacy bare component ids and per-unit objects by wrapping
     * scalars into {tipo_id}, so downstream code always sees one shape.
     */
    protected function prepareForValidation(): void
    {
        $componentes = $this->input('componentes');

        if (! is_array($componentes)) {
            return;
        }

        $normalized = [];

        foreach ($componentes as $key => $componente) {
            $normalized[$key] = is_array($componente) ? $componente : ['tipo_id' => $componente];
        }

        $this->merge(['componentes' => $normalized]);
    }

    public function rules(): array
    {
        return [
            'paciente_id' => ['required', 'integer', 'exists:pacientes,id'],
            'tipo_id' => ['required', 'integer', 'exists:tipos_donacion,id'],
            'sede_id' => ['sometimes', 'nullable', 'integer', 'exists:sedes,id'],
            'componentes' => ['required', 'array', 'min:1'],
            'componentes.*' => ['array'],
            'componentes.*.tipo_id' => ['required', 'integer', 'exists:tipos_donacion,id'],
            'fecha' => ['sometimes', 'date'],
            'forzar' => ['sometimes', 'boolean'],
        ];
    }
}
