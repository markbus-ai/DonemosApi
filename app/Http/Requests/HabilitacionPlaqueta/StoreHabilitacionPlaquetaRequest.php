<?php

namespace App\Http\Requests\HabilitacionPlaqueta;

use Illuminate\Foundation\Http\FormRequest;

class StoreHabilitacionPlaquetaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'desde' => ['sometimes', 'nullable', 'date'],
            'hasta' => ['sometimes', 'nullable', 'date', 'after:desde'],
        ];
    }
}
