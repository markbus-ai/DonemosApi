<?php

namespace Database\Factories;

use App\Models\Sede;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Sede> */
class SedeFactory extends Factory
{
    protected $model = Sede::class;

    public function definition(): array
    {
        return [
            'nombre' => 'Sede '.fake()->unique()->city(),
            // Three digits, never 000, so donation numbers can be minted.
            'codigo_localidad' => (string) fake()->unique()->numberBetween(100, 999),
            'activa' => true,
            'es_default' => false,
        ];
    }

    /** The flagged default sede ("Sede Central" in the seeders). */
    public function porDefecto(): static
    {
        return $this->state(['es_default' => true]);
    }

    public function sinCodigo(): static
    {
        return $this->state(['codigo_localidad' => null]);
    }
}
