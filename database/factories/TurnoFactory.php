<?php

namespace Database\Factories;

use App\Enums\TurnoEstado;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\Turno;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Turno> */
class TurnoFactory extends Factory
{
    protected $model = Turno::class;

    public function definition(): array
    {
        return [
            'paciente_id' => Paciente::factory(),
            'sede_id' => Sede::factory(),
            'fecha' => now()->addDay()->toDateString(),
            'hora' => '09:00',
            'estado' => TurnoEstado::Pendiente->value,
        ];
    }

    public function cancelado(): static
    {
        return $this->state(['estado' => TurnoEstado::Cancelado->value]);
    }

    public function atendido(): static
    {
        return $this->state(['estado' => TurnoEstado::Atendido->value]);
    }
}
