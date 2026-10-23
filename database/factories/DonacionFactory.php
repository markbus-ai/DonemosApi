<?php

namespace Database\Factories;

use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\TipoDonacion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A historical donation row written directly (no rules, no numbering). Pass
 * `tipo_id` when the test already created the type, since codes are unique.
 *
 * @extends Factory<Donacion>
 */
class DonacionFactory extends Factory
{
    protected $model = Donacion::class;

    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'sede_id' => Sede::factory(),
            'tipo_id' => fn () => TipoDonacion::firstOrCreate(['codigo' => 'SANGRE'], ['nombre' => 'SANGRE'])->id,
            'fecha' => now()->toDateString(),
            'numero_donacion' => null,
        ];
    }
}
