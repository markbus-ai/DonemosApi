<?php

namespace App\Http\Requests\Restriccion;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestriccionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo_id' => ['required', 'integer', 'exists:motivos,id'],
            'desde' => ['required', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31'],
            'permanente' => ['sometimes', 'boolean'],
            // A permanent deferral has no return date; history is closed by the service.
            'hasta' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31', 'after:desde', 'prohibited_if:permanente,true',
                // A temporary (non-permanent) deferral needs its end date.
                Rule::requiredIf(fn () => $this->has('permanente') && ! $this->boolean('permanente'))],
        ];
    }
}
