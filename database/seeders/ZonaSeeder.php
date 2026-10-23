<?php

namespace Database\Seeders;

use App\Models\Rol;
use App\Models\Sede;
use App\Models\Usuario;
use App\Models\Zona;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ZonaSeeder extends Seeder
{
    public const ZONA_DEFAULT = 'Costa Atlántica';

    /**
     * Group every sede without a zona into the default zona. Idempotent.
     */
    public function run(): void
    {
        $zona = Zona::firstOrCreate(['nombre' => self::ZONA_DEFAULT]);

        Sede::whereNull('zona_id')->update(['zona_id' => $zona->id]);
    }

    /**
     * Local/dev admin logins (admin-sede on Sede Central, admin-zona on the
     * default zona). Demo-only: never seeded on real deploys.
     */
    public static function seedDemoAdmins(): void
    {
        $zona = Zona::firstOrCreate(['nombre' => self::ZONA_DEFAULT]);

        $password = env('SEED_ADMIN_PASSWORD', '1234');

        Usuario::updateOrCreate(
            ['username' => env('SEED_ADMIN_SEDE_USERNAME', 'ADS')],
            [
                'password_hash' => Hash::make($password),
                'rol_id' => Rol::firstOrCreate(['nombre' => Usuario::ROL_ADMIN_SEDE])->id,
                'sede_id' => Sede::where('nombre', 'Sede Central')->value('id'),
                'zona_id' => null,
            ]
        );

        Usuario::updateOrCreate(
            ['username' => env('SEED_ADMIN_ZONA_USERNAME', 'ADZ')],
            [
                'password_hash' => Hash::make($password),
                'rol_id' => Rol::firstOrCreate(['nombre' => Usuario::ROL_ADMIN_ZONA])->id,
                'sede_id' => null,
                'zona_id' => $zona->id,
            ]
        );
    }
}
