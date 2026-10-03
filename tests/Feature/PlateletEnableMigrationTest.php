<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\HabilitacionPlaqueta;
use App\Models\Paciente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WU-B surface: additive platelet-enable schema and the model contract that
 * exposes it.
 */
class PlateletEnableMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_03_000012_create_habilitaciones_plaquetas_table.php';

    private function migration(): object
    {
        $path = database_path(self::MIGRATION);

        if (! file_exists($path)) {
            $this->fail('Platelet-enable migration file has not been created yet: '.self::MIGRATION);
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

    public function test_habilitaciones_plaquetas_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('habilitaciones_plaquetas'));

        foreach (['id', 'paciente_id', 'desde', 'hasta', 'created_by', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('habilitaciones_plaquetas', $column),
                "Missing habilitaciones_plaquetas.{$column} column.",
            );
        }
    }

    public function test_enable_persists_and_resolves_paciente(): void
    {
        $paciente = $this->paciente();

        $enable = HabilitacionPlaqueta::create([
            'paciente_id' => $paciente->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-04-01',
        ]);

        $fresh = $enable->fresh();

        $this->assertTrue($fresh->paciente->is($paciente));
        $this->assertSame('2026-01-01', $fresh->desde);
        $this->assertSame('2026-04-01', $fresh->hasta);
        $this->assertNull($fresh->created_by);
    }

    public function test_patient_exposes_enable_history(): void
    {
        $paciente = $this->paciente();

        $paciente->habilitacionesPlaquetas()->createMany([
            ['desde' => '2026-01-01', 'hasta' => '2026-02-01'],
            ['desde' => '2026-03-01', 'hasta' => '2026-04-01'],
        ]);

        $this->assertSame(2, $paciente->fresh()->habilitacionesPlaquetas()->count());
    }

    public function test_scope_vigente_matches_only_the_current_window(): void
    {
        $paciente = $this->paciente();

        $paciente->habilitacionesPlaquetas()->createMany([
            ['desde' => '2026-01-01', 'hasta' => '2026-02-01'],
            ['desde' => '2026-03-01', 'hasta' => '2026-04-01'],
        ]);

        $this->assertSame(1, HabilitacionPlaqueta::where('paciente_id', $paciente->id)->vigente('2026-03-15')->count());
        $this->assertSame(0, HabilitacionPlaqueta::where('paciente_id', $paciente->id)->vigente('2026-02-15')->count());
    }

    public function test_rollback_drops_table(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('habilitaciones_plaquetas'));

        // The rollback must not touch unrelated schema.
        $this->assertTrue(Schema::hasTable('pacientes'));
    }
}
