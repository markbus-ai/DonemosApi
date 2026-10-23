<?php

namespace App\Http\Requests\Turno;

use App\Enums\TurnoEstado;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTurnoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'estado' => ['required', Rule::in(TurnoEstado::values())],
        ];
    }
}
