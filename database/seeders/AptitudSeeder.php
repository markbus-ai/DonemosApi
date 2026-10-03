<?php

namespace Database\Seeders;

use App\Models\Aptitud;
use Illuminate\Database\Seeder;

class AptitudSeeder extends Seeder
{
    /**
     * Seed aptitudes lookup table.
     */
    public function run(): void
    {
        $tipos = [
            'APTO',
            'APTO_OBSERVACION',
            'NO_APTO',
        ];

        foreach ($tipos as $tipo) {
            Aptitud::firstOrCreate(['tipo' => $tipo]);
        }
    }
}
