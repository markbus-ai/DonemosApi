<?php

namespace Database\Seeders;

use App\Models\TipoDonacion;
use Illuminate\Database\Seeder;

class TipoDonacionSeeder extends Seeder
{
    /**
     * Seed tipos_donacion lookup table.
     */
    public function run(): void
    {
        // codigo is the stable product key; nombre is display-only.
        foreach (['SANGRE', 'PLASMA', 'PLAQUETAS'] as $codigo) {
            TipoDonacion::updateOrCreate(['codigo' => $codigo], ['nombre' => $codigo]);
        }
    }
}
