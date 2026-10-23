<?php

namespace App\Http\Requests\Turno;

use Illuminate\Foundation\Http\FormRequest;

class IndexTurnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paciente_id' => ['nullable', 'integer'],
            'fecha' => ['nullable', 'date_format:Y-m-d'],
            'desde' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
