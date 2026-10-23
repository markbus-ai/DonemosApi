<?php

namespace Database\Factories;

use App\Models\Aptitud;
use App\Models\Paciente;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Paciente> */
class PacienteFactory extends Factory
{
    protected $model = Paciente::class;

    public function definition(): array
    {
        return [
            'dni' => (string) fake()->unique()->numberBetween(10000000, 99999999),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '2254 123456',
            'sexo' => 'F',
            'aptitud_id' => fn () => self::aptitud('APTO'),
        ];
    }

    public function noApto(): static
    {
        return $this->state(['aptitud_id' => fn () => self::aptitud('NO_APTO')]);
    }

    public function aptoObservacion(): static
    {
        return $this->state(['aptitud_id' => fn () => self::aptitud('APTO_OBSERVACION')]);
    }

    public function masculino(): static
    {
        return $this->state(['sexo' => 'M']);
    }

    private static function aptitud(string $tipo): int
    {
        return Aptitud::firstOrCreate(['tipo' => $tipo])->id;
    }
}
