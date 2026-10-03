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
        $nombres = [
            'PLASMA',
            'PLAQUETAS',
        ];

        foreach ($nombres as $nombre) {
            TipoDonacion::firstOrCreate(['nombre' => $nombre]);
        }
    }
}
