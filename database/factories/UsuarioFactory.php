<?php

namespace Database\Factories;

use App\Enums\RolNombre;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Usuario;
use App\Models\Zona;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<Usuario> */
class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'username' => 'staff_'.fake()->unique()->bothify('????####'),
            'password_hash' => Hash::make('1234'),
            'rol_id' => fn () => self::rol(RolNombre::Staff),
            'activo' => true,
        ];
    }

    public function rolNombre(RolNombre|string $rol): static
    {
        return $this->state(['rol_id' => fn () => self::rol($rol)]);
    }

    /** Admin of one sede (a new one unless given). */
    public function adminSede(?Sede $sede = null): static
    {
        return $this->state([
            'rol_id' => fn () => self::rol(RolNombre::AdminSede),
            'sede_id' => $sede?->id ?? Sede::factory(),
        ]);
    }

    /** Admin of a whole zona (a new one unless given); no home sede. */
    public function adminZona(?Zona $zona = null): static
    {
        return $this->state([
            'rol_id' => fn () => self::rol(RolNombre::AdminZona),
            'zona_id' => $zona?->id ?? Zona::factory(),
        ]);
    }

    public function enSede(Sede $sede): static
    {
        return $this->state(['sede_id' => $sede->id]);
    }

    public function inactivo(): static
    {
        return $this->state(['activo' => false]);
    }

    private static function rol(RolNombre|string $rol): int
    {
        return Rol::firstOrCreate(['nombre' => $rol instanceof RolNombre ? $rol->value : $rol])->id;
    }
}
