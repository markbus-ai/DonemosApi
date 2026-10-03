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
        // PLASMA and PLAQUETAS are apheresis by product assumption (spec S5).
        $catalogo = [
            'SANGRE' => false,
            'PLASMA' => true,
            'PLAQUETAS' => true,
        ];

        foreach ($catalogo as $codigo => $esAferesis) {
            TipoDonacion::updateOrCreate(
                ['codigo' => $codigo],
                ['nombre' => $codigo, 'es_aferesis' => $esAferesis],
            );
        }
    }
}
