<?php

namespace Database\Seeders;

use App\Models\Sede;
use Illuminate\Database\Seeder;

/**
 * Idempotent locality-code mapping for the known sedes.
 *
 * Only the central sede is active today; the later postas are seeded inactive
 * so numbering stays fail-closed until they are enabled.
 */
class SedeSeeder extends Seeder
{
    public function run(): void
    {
        $sedes = [
            ['nombre' => 'Sede Central', 'codigo_localidad' => '1', 'activa' => true],
            ['nombre' => 'Pinamar', 'codigo_localidad' => '003', 'activa' => false],
            ['nombre' => 'Mar de Ajó', 'codigo_localidad' => '030', 'activa' => false],
            ['nombre' => 'Madariaga', 'codigo_localidad' => '004', 'activa' => false],
        ];

        foreach ($sedes as $sede) {
            Sede::updateOrCreate(['nombre' => $sede['nombre']], $sede);
        }
    }
}
