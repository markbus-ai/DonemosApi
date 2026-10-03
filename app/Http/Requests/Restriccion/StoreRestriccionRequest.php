<?php

namespace App\Http\Requests\Restriccion;

use Illuminate\Foundation\Http\FormRequest;

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
            'desde' => ['required', 'date'],
            'permanente' => ['sometimes', 'boolean'],
            // A permanent deferral has no return date; history is closed by the service.
            'hasta' => ['nullable', 'date', 'after_or_equal:desde', 'prohibited_if:permanente,true'],
        ];
    }
}
