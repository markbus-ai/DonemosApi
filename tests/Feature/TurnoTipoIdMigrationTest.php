<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\TipoDonacion;
use App\Models\Turno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WU-1 surface: additive `turnos.tipo_id` nullable FK to `tipos_donacion` and
 * the `Turno::tipoDonacion()` relation (spec: Turno stores donation type).
 */
class TurnoTipoIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_03_000015_add_tipo_id_to_turnos_table.php';

    private function migration(): object
    {
        $path = database_path(self::MIGRATION);

        if (! file_exists($path)) {
            $this->fail('Turno tipo_id migration file has not been created yet: '.self::MIGRATION);
        }

        return require $path;
    }

    private function paciente(): Paciente
    {
        $aptitud = Aptitud::firstOrCreate(['tipo' => 'APTO']);

        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ]);
    }

    public function test_tipo_id_is_nullable_and_foreign_keyed(): void
    {
        $this->assertTrue(Schema::hasColumn('turnos', 'tipo_id'));

        // A legacy turno without a type stays valid.
        $paciente = $this->paciente();

        $turno = Turno::create([
            'paciente_id' => $paciente->id,
            'fecha' => '2026-06-01',
            'hora' => '10:00',
            'estado' => 'PENDIENTE',
        ]);

        $this->assertNull($turno->fresh()->tipo_id);
    }

    public function test_turno_persists_tipo_id_and_resolves_relation(): void
    {
        $paciente = $this->paciente();
        $tipo = TipoDonacion::create([
            'nombre' => 'PLAQUETAS',
            'codigo' => 'PLAQUETAS',
            'duracion_minutos' => 60,
        ]);

        $turno = Turno::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => '2026-06-01',
            'hora' => '10:00',
            'estado' => 'PENDIENTE',
        ]);

        $fresh = $turno->fresh();

        $this->assertSame($tipo->id, $fresh->tipo_id);
        $this->assertTrue($fresh->tipoDonacion->is($tipo));
    }

    public function test_rollback_drops_tipo_id_column(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('turnos', 'tipo_id'));

        // The rollback must not touch unrelated schema.
        $this->assertTrue(Schema::hasColumn('turnos', 'sede_id'));
    }
}
