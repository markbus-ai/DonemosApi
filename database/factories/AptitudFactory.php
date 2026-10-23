<?php

namespace Database\Factories;

use App\Models\Aptitud;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Aptitud is a three-row catalog: Aptitud::firstOrCreate(['tipo' => ...]) is
 * usually what a test wants; the factory serves isolated catalog tests.
 *
 * @extends Factory<Aptitud>
 */
class AptitudFactory extends Factory
{
    protected $model = Aptitud::class;

    public function definition(): array
    {
        return ['tipo' => 'APTO'];
    }

    public function noApto(): static
    {
        return $this->state(['tipo' => 'NO_APTO']);
    }

    public function aptoObservacion(): static
    {
        return $this->state(['tipo' => 'APTO_OBSERVACION']);
    }
}
