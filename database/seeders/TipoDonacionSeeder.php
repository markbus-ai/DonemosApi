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
        // duracion_minutos is a product assumption (spec A2/A3): whole blood is
        // short, apheresis is longer. Needs Hemocentro sign-off.
        $catalogo = [
            'SANGRE' => ['es_aferesis' => false, 'duracion_minutos' => 30],
            'PLASMA' => ['es_aferesis' => true, 'duracion_minutos' => 60],
            'PLAQUETAS' => ['es_aferesis' => true, 'duracion_minutos' => 60],
        ];

        foreach ($catalogo as $codigo => $atributos) {
            TipoDonacion::updateOrCreate(
                ['codigo' => $codigo],
                ['nombre' => $codigo, ...$atributos],
            );
        }
    }
}
