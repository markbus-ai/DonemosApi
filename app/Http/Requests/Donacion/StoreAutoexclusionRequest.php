<?php

namespace App\Http\Requests\Donacion;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Confidential self-exclusion payload: only an optional reason.
 *
 * The donation is identified by `numero_donacion` in the route; no patient
 * identity is ever accepted here.
 */
class StoreAutoexclusionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'motivo' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
