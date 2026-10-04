<?php

namespace Tests\Feature;

use App\Models\TipoDonacion;
use Database\Seeders\TipoDonacionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WU-1 surface: additive `tipos_donacion.duracion_minutos` column and the
 * per-code duration catalog emitted by the seeder (spec A3).
 */
class TipoDonacionDurationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_03_000014_add_duracion_minutos_to_tipos_donacion_table.php';

    private function migration(): object
    {
        $path = database_path(self::MIGRATION);

        if (! file_exists($path)) {
            $this->fail('Tipo donacion duration migration file has not been created yet: '.self::MIGRATION);
        }

        return require $path;
    }

    public function test_duracion_minutos_is_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('tipos_donacion', 'duracion_minutos'));

        // Legacy rows must keep working: no duration is not an error.
        $id = DB::table('tipos_donacion')->insertGetId([
            'nombre' => 'legacy',
            'codigo' => 'LEGACY',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertNull(DB::table('tipos_donacion')->where('id', $id)->value('duracion_minutos'));
    }

    public function test_model_exposes_duracion_minutos(): void
    {
        $tipo = TipoDonacion::create([
            'nombre' => 'SANGRE',
            'codigo' => 'SANGRE',
            'duracion_minutos' => 30,
        ]);

        $this->assertSame(30, $tipo->fresh()->duracion_minutos);
    }

    public function test_seeder_assigns_non_null_duration_per_code(): void
    {
        (new TipoDonacionSeeder)->run();

        foreach (['SANGRE', 'PLASMA', 'PLAQUETAS'] as $codigo) {
            $this->assertNotNull(
                TipoDonacion::where('codigo', $codigo)->value('duracion_minutos'),
                "Seeder left {$codigo} without a duration.",
            );
        }
    }

    public function test_seeder_makes_apheresis_longer_than_whole_blood(): void
    {
        (new TipoDonacionSeeder)->run();

        $sangre = (int) TipoDonacion::where('codigo', 'SANGRE')->value('duracion_minutos');

        foreach (['PLASMA', 'PLAQUETAS'] as $codigo) {
            $aferesis = (int) TipoDonacion::where('codigo', $codigo)->value('duracion_minutos');

            $this->assertGreaterThan($sangre, $aferesis, "{$codigo} must outlast whole blood.");
        }
    }

    public function test_rollback_drops_duracion_minutos_column(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('tipos_donacion', 'duracion_minutos'));

        // The rollback must not touch unrelated schema.
        $this->assertTrue(Schema::hasColumn('tipos_donacion', 'codigo'));
    }
}
