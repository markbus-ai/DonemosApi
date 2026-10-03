<?php

namespace App\Http\Requests\Aptitud;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAptitudRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::in(['APTO', 'APTO_OBSERVACION', 'NO_APTO'])],
            'motivo_id' => [
                'nullable',
                'integer',
                'exists:motivos,id',
                Rule::requiredIf(fn () => in_array($this->input('tipo'), ['APTO_OBSERVACION', 'NO_APTO'])),
            ],
            'desde' => [
                'nullable',
                'date',
                Rule::requiredIf(fn () => in_array($this->input('tipo'), ['APTO_OBSERVACION', 'NO_APTO'])),
            ],
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ];
    }
}
