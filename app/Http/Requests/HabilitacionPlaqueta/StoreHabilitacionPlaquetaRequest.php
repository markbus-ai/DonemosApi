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
            'desde' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31'],
            'hasta' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2099-12-31', 'after:desde'],
        ];
    }
}
