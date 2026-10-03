<?php

namespace App\Http\Requests\Donacion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDonacionRequest extends FormRequest
{
    /** Accepted collection bag types (A5). */
    public const TIPOS_BOLSA = ['doble', 'triple', 'cuadruple', 'quintuple'];

    /** Accepted venipuncture arms (A5). */
    public const BRAZOS = ['izquierdo', 'derecho'];

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
            'componentes.*.vencimiento' => ['sometimes', 'nullable', 'date'],
            'componentes.*.peso' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'fecha' => ['sometimes', 'date'],
            'forzar' => ['sometimes', 'boolean'],
            // operador_id is never accepted from the client; it comes from auth.
            'tipo_bolsa' => ['sometimes', 'nullable', Rule::in(self::TIPOS_BOLSA)],
            'anticoagulante' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lote' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tubuladura' => ['sometimes', 'nullable', 'string', 'max:255'],
            'brazo' => ['sometimes', 'nullable', Rule::in(self::BRAZOS)],
            'dificultad' => ['sometimes', 'nullable', 'string', 'max:255'],
            'doble_etiqueta' => ['sometimes', 'boolean'],
        ];
    }
}
