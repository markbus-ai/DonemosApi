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
                'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31',
                Rule::requiredIf(fn () => in_array($this->input('tipo'), ['APTO_OBSERVACION', 'NO_APTO'])),
            ],
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31', 'after:desde', 'prohibited_if:permanente,true',
                Rule::requiredIf(fn () => $this->input('tipo') === 'NO_APTO' && $this->has('permanente') && ! $this->boolean('permanente'))],
            'permanente' => ['sometimes', 'boolean'],
        ];
    }
}
