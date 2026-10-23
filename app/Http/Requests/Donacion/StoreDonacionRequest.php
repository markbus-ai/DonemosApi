<?php

namespace App\Http\Requests\Donacion;

use App\Support\Hoy;
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
            'componentes.*.peso' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:5000'],
            // Back-dated entry is allowed (paper records); future dates are not.
            'fecha' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:'.Hoy::fecha()],
            // Manual cycle metadata; optional and never inferred. `donacion` is
            // the ordinal within the serie.
            // An override needs all three values; a partial one would be ignored.
            'ciclo' => ['nullable', 'integer', 'min:1', 'max:1000', 'required_with:serie,donacion'],
            'serie' => ['nullable', 'integer', 'min:1', 'max:'.config('ciclo_donacion.series_por_ciclo'), 'required_with:ciclo,donacion'],
            'donacion' => ['nullable', 'integer', 'min:1', 'max:'.config('ciclo_donacion.donaciones_por_serie'), 'required_with:ciclo,serie'],
            'forzar' => ['sometimes', 'boolean'],
            // Forcing past clinical warnings is audited with the operator's reason.
            'motivo' => ['nullable', 'string', 'min:5', 'max:500', Rule::requiredIf(fn () => $this->boolean('forzar'))],
            // operador_id is never accepted from the client; it comes from auth.
            'tipo_bolsa' => ['sometimes', 'nullable', Rule::in(self::TIPOS_BOLSA)],
            'anticoagulante' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lote' => ['sometimes', 'nullable', 'string', 'max:255'],
            'tubuladura' => ['sometimes', 'nullable', 'string', 'max:255'],
            'brazo' => ['sometimes', 'nullable', Rule::in(self::BRAZOS)],
            'dificultad' => ['sometimes', 'nullable', 'string', 'max:255'],
            'doble_etiqueta' => ['sometimes', 'boolean'],
            // Clinical signs: shape/numeric only. Gating and integrity beyond
            // the shape (apheresis precondition, sourced warnings) live in
            // ClinicalSignRules and DonacionService.
            // Physical-possibility bounds only: they reject impossible readings
            // and never act as clinical thresholds (capture_only stays ungated).
            'hemoglobina' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:30'],
            'hematocrito' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:99.99'],
            'plaquetas' => ['sometimes', 'nullable', 'integer', 'between:1,5000000'],
            'presion_sistolica' => ['sometimes', 'nullable', 'integer', 'between:1,350'],
            'presion_diastolica' => ['sometimes', 'nullable', 'integer', 'between:1,250', Rule::when($this->filled('presion_sistolica'), ['lt:presion_sistolica'])],
            'frecuencia_cardiaca' => ['sometimes', 'nullable', 'integer', 'between:1,350'],
            'peso_donante' => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:500'],
        ];
    }
}
