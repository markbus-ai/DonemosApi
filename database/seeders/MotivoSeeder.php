<?php

namespace Database\Seeders;

use App\Models\Motivo;
use Illuminate\Database\Seeder;

class MotivoSeeder extends Seeder
{
    /**
     * Catalog of deferral / observation reasons. Plazos are left null: the
     * hemocentro must confirm each deferral period before it is encoded.
     */
    public function run(): void
    {
        $motivos = [
            'TATUAJE_PIERCING' => 'Tatuaje, piercing o acupuntura reciente',
            'CIRUGIA' => 'Cirugía o procedimiento invasivo reciente',
            'ENDOSCOPIA' => 'Endoscopía reciente',
            'EMBARAZO' => 'Embarazo, parto o lactancia',
            'VACUNA' => 'Vacunación reciente',
            'INFECCION' => 'Fiebre o infección reciente',
            'MEDICACION' => 'Medicación en curso',
            'VIAJE_ENDEMICO' => 'Viaje a zona endémica',
            'CONDUCTA_RIESGO' => 'Conducta de riesgo reciente',
            'HEMOGLOBINA_BAJA' => 'Hemoglobina baja',
            'PESO_BAJO' => 'Peso por debajo del mínimo',
            'ENFERMEDAD_CRONICA' => 'Enfermedad crónica que contraindica la donación',
            'SEROLOGIA_REACTIVA' => 'Serología reactiva',
            'OTRO' => 'Otro (ver observación clínica)',
        ];

        foreach ($motivos as $codigo => $nombre) {
            Motivo::updateOrCreate(['codigo' => $codigo], ['nombre' => $nombre]);
        }
    }
}
