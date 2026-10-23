<?php

namespace Database\Factories;

use App\Models\TipoDonacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Codes are unique: use one state per code in a test (sangre, plasma,
 * plaquetas), matching TipoDonacionSeeder.
 *
 * @extends Factory<TipoDonacion>
 */
class TipoDonacionFactory extends Factory
{
    protected $model = TipoDonacion::class;

    public function definition(): array
    {
        return ['codigo' => 'SANGRE', 'nombre' => 'SANGRE', 'es_aferesis' => false, 'duracion_minutos' => 30];
    }

    public function sangre(): static
    {
        return $this->state(['codigo' => 'SANGRE', 'nombre' => 'SANGRE', 'es_aferesis' => false, 'duracion_minutos' => 30]);
    }

    public function plasma(): static
    {
        return $this->state(['codigo' => 'PLASMA', 'nombre' => 'PLASMA', 'es_aferesis' => true, 'duracion_minutos' => 60]);
    }

    public function plaquetas(): static
    {
        return $this->state(['codigo' => 'PLAQUETAS', 'nombre' => 'PLAQUETAS', 'es_aferesis' => true, 'duracion_minutos' => 60]);
    }
}
