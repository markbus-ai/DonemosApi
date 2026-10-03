<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsuarioSeeder extends Seeder
{
    /**
     * Seed a local/dev staff login. The secret comes from the environment.
     */
    public function run(): void
    {
        $username = env('SEED_STAFF_USERNAME', 'AD');
        $password = env('SEED_STAFF_PASSWORD', '1234');
        $rolNombre = env('SEED_STAFF_ROLE', 'staff');

        $rol = Rol::firstOrCreate(['nombre' => $rolNombre]);

        Usuario::updateOrCreate(
            ['username' => $username],
            [
                'password_hash' => Hash::make($password),
                'rol_id' => $rol->id,
            ]
        );
    }
}
