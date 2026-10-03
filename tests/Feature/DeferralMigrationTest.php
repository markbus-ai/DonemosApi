<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\Restriccion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Forward/backward behaviour of the additive deferral-model migration.
 */
class DeferralMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_02_000001_add_deferral_model.php';

    private function migration(): object
    {
        $path = database_path(self::MIGRATION);

        if (! file_exists($path)) {
            $this->fail('Deferral migration file has not been created yet: '.self::MIGRATION);
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

    public function test_unique_paciente_id_is_dropped_and_multiple_rows_allowed(): void
    {
        $paciente = $this->paciente();
        $motivo = Motivo::create(['nombre' => 'motivo_a']);

        Restriccion::create([
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
        ]);
        Restriccion::create([
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo->id,
            'desde' => '2026-02-01',
        ]);

        $this->assertSame(2, Restriccion::where('paciente_id', $paciente->id)->count());
    }

    public function test_permanente_column_defaults_to_false(): void
    {
        $paciente = $this->paciente();
        $motivo = Motivo::create(['nombre' => 'motivo_b']);

        $restriccion = Restriccion::create([
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-04-01',
        ]);

        $this->assertFalse((bool) DB::table('restricciones')->where('id', $restriccion->id)->value('permanente'));
    }

    public function test_null_hasta_is_backfilled_to_permanent(): void
    {
        // Roll back to the legacy schema so a pre-migration row can be seeded.
        $this->migration()->down();

        $paciente = $this->paciente();
        $motivoId = DB::table('motivos')->insertGetId([
            'nombre' => 'legacy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $restriccionId = DB::table('restricciones')->insertGetId([
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivoId,
            'desde' => '2025-01-01',
            'hasta' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertTrue((bool) DB::table('restricciones')->where('id', $restriccionId)->value('permanente'));
    }

    public function test_motivo_codigo_is_unique(): void
    {
        Motivo::create(['nombre' => 'tatuaje_1', 'codigo' => 'TATUAJE']);

        $this->expectException(QueryException::class);

        Motivo::create(['nombre' => 'tatuaje_2', 'codigo' => 'TATUAJE']);
    }

    public function test_motivo_plazo_meses_is_nullable(): void
    {
        $motivo = Motivo::create(['nombre' => 'temporal', 'codigo' => 'TEMP', 'plazo_meses' => 6]);

        $this->assertSame(6, (int) DB::table('motivos')->where('id', $motivo->id)->value('plazo_meses'));

        $permanente = Motivo::create(['nombre' => 'permanente_referencia', 'codigo' => 'PERM']);

        $this->assertNull(DB::table('motivos')->where('id', $permanente->id)->value('plazo_meses'));
    }

    public function test_rollback_fails_loudly_when_a_patient_has_multiple_rows(): void
    {
        $paciente = $this->paciente();
        $motivo = Motivo::create(['nombre' => 'motivo_c']);

        Restriccion::create(['paciente_id' => $paciente->id, 'motivo_id' => $motivo->id, 'desde' => '2026-01-01']);
        Restriccion::create(['paciente_id' => $paciente->id, 'motivo_id' => $motivo->id, 'desde' => '2026-02-01']);

        $this->expectException(\RuntimeException::class);

        $this->migration()->down();
    }

    public function test_rollback_restores_unique_when_history_is_single_row(): void
    {
        $paciente = $this->paciente();
        $motivo = Motivo::create(['nombre' => 'motivo_d']);

        Restriccion::create(['paciente_id' => $paciente->id, 'motivo_id' => $motivo->id, 'desde' => '2026-01-01']);

        $this->migration()->down();

        $this->expectException(QueryException::class);

        DB::table('restricciones')->insert([
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo->id,
            'desde' => '2026-02-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
