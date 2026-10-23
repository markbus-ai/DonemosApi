<?php

namespace Database\Factories;

use App\Enums\RolNombre;
use App\Models\Rol;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Role names are unique: prefer Usuario::factory() states, which reuse the
 * existing row, when several users share a role.
 *
 * @extends Factory<Rol>
 */
class RolFactory extends Factory
{
    protected $model = Rol::class;

    public function definition(): array
    {
        return ['nombre' => RolNombre::Staff->value];
    }

    public function adminSede(): static
    {
        return $this->state(['nombre' => RolNombre::AdminSede->value]);
    }

    public function adminZona(): static
    {
        return $this->state(['nombre' => RolNombre::AdminZona->value]);
    }
}
