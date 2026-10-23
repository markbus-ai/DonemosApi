<?php

namespace Database\Factories;

use App\Models\Motivo;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Motivo> */
class MotivoFactory extends Factory
{
    protected $model = Motivo::class;

    public function definition(): array
    {
        return [
            'nombre' => 'Motivo '.fake()->unique()->word(),
            'codigo' => null,
            'plazo_meses' => null,
        ];
    }
}
