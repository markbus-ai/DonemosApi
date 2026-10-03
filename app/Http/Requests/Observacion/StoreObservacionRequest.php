<?php

namespace App\Http\Requests\Observacion;

use Illuminate\Foundation\Http\FormRequest;

class StoreObservacionRequest extends FormRequest
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
            'hasta' => ['nullable', 'date', 'after_or_equal:desde'],
        ];
    }
}
