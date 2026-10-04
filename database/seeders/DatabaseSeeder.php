<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            AptitudSeeder::class,
            TipoDonacionSeeder::class,
            UsuarioSeeder::class,
            SedeSeeder::class,
        ]);

        // Demo data is opt-in only. Default false so real deploys never receive
        // demo patients or fake turnos.
        if (filter_var(env('SEED_DEMO_DATA', false), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoSeeder::class);
        }
    }
}
