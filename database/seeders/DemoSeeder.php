<?php

namespace Database\Seeders;

use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Turno;
use Illuminate\Database\Seeder;

/**
 * Dev/demo seed: makes a fresh install look alive on first login.
 *
 * Safety contract — this is DEMO data, never clinical truth:
 * - Patients use obviously-fake identities (DNI 900000xx, "Demo Uno" / "Paciente").
 * - Clinical-sign columns stay NULL; no donations are seeded at all.
 * - Deferral, self-exclusion and platelet-enable tables stay empty.
 *
 * Gated behind SEED_DEMO_DATA (default false) so real deploys never get it.
 * Idempotent: safe to re-run without duplicating rows or breaking uniques.
 */
class DemoSeeder extends Seeder
{
    /**
     * Number of demo patients. Kept at 10 to fill the agenda without noise.
     */
    private const PATIENTOS = 10;

    private const SEDE = 'Sede Central';

    /**
     * Real agenda hours. Two slots per day across four days: the heaviest day
     * sums 2 x 60 = 120 min of donation time, well under the 480 min/day cap.
     */
    private const HORARIOS = ['08:00', '10:00'];

    public function run(): void
    {
        $apto = Aptitud::where('tipo', 'APTO')->firstOrFail();
        $sede = Sede::where('nombre', self::SEDE)->firstOrFail();

        $pacientes = $this->seedPacientes($apto);
        $tipos = $this->resolveTipos();

        $this->seedTurnos($sede, $pacientes, $tipos);
    }

    /**
     * @return array<int, Paciente>
     */
    private function seedPacientes(Aptitud $apto): array
    {
        $pacientes = [];

        for ($i = 1; $i <= self::PATIENTOS; $i++) {
            $pacientes[] = Paciente::updateOrCreate(
                ['dni' => (string) (90000000 + $i)],
                [
                    'nombre' => 'Demo '.$i,
                    'apellido' => 'Paciente',
                    'telefono' => '1100000'.str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                    'sexo' => $i % 2 === 0 ? 'M' : 'F',
                    'altura' => 165 + ($i % 16),
                    'aptitud_id' => $apto->id,
                ],
            );
        }

        return $pacientes;
    }

    /**
     * Real donation types; missing catalog rows are created generically rather
     * than hardcoding ids.
     *
     * @return array<int, TipoDonacion>
     */
    private function resolveTipos(): array
    {
        $catalogo = [
            'SANGRE' => ['es_aferesis' => false, 'duracion_minutos' => 30],
            'PLASMA' => ['es_aferesis' => true, 'duracion_minutos' => 60],
            'PLAQUETAS' => ['es_aferesis' => true, 'duracion_minutos' => 60],
        ];

        $tipos = [];

        foreach ($catalogo as $codigo => $atributos) {
            $tipos[] = TipoDonacion::firstOrCreate(
                ['codigo' => $codigo],
                ['nombre' => $codigo, ...$atributos],
            );
        }

        return $tipos;
    }

    /**
     * @param  array<int, Paciente>  $pacientes
     * @param  array<int, TipoDonacion>  $tipos
     */
    private function seedTurnos(Sede $sede, array $pacientes, array $tipos): void
    {
        // Today first, then the next few days, so first login sees same-day turnos.
        $dias = [
            now()->startOfDay(),
            now()->addDay()->startOfDay(),
            now()->addDays(2)->startOfDay(),
            now()->addDays(3)->startOfDay(),
        ];

        $indice = 0;

        foreach ($dias as $fecha) {
            foreach (self::HORARIOS as $hora) {
                $paciente = $pacientes[$indice % count($pacientes)];
                $tipo = $tipos[$indice % count($tipos)];

                // Key on the same (sede_id, fecha, hora) the DB unique enforces,
                // so a second run updates the slot instead of inserting a
                // duplicate. fecha stays a date object so the query binding
                // matches the stored row (date-only strings would not).
                Turno::updateOrCreate(
                    [
                        'sede_id' => $sede->id,
                        'fecha' => $fecha,
                        'hora' => $hora,
                    ],
                    [
                        'paciente_id' => $paciente->id,
                        'tipo_id' => $tipo->id,
                        'estado' => 'PENDIENTE',
                    ],
                );

                $indice++;
            }
        }
    }
}
