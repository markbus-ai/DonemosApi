<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\TipoDonacion;
use Database\Seeders\TipoDonacionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WU-A surface: additive clinical-sign schema, apheresis flag backfill and the
 * model fillable/cast/accessor contract. No gating behaviour lives here.
 */
class ClinicalSignsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const SIGNS_MIGRATION = 'migrations/2026_10_03_000008_add_clinical_signs_to_donaciones_table.php';

    private const AFERESIS_MIGRATION = 'migrations/2026_10_03_000009_add_es_aferesis_to_tipos_donacion_table.php';

    private const SIGN_COLUMNS = [
        'hemoglobina',
        'hematocrito',
        'plaquetas',
        'presion_sistolica',
        'presion_diastolica',
        'frecuencia_cardiaca',
        'peso_donante',
    ];

    private const FECHA = '2026-05-04';

    private function migration(string $file): object
    {
        $path = database_path($file);

        if (! file_exists($path)) {
            $this->fail('Migration file has not been created yet: '.$file);
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

    private function tipo(string $codigo): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo]);
    }

    public function test_clinical_sign_columns_exist(): void
    {
        foreach (self::SIGN_COLUMNS as $column) {
            $this->assertTrue(
                Schema::hasColumn('donaciones', $column),
                "Missing donaciones.{$column} column.",
            );
        }
    }

    public function test_clinical_signs_rollback_drops_every_added_column(): void
    {
        $this->migration(self::SIGNS_MIGRATION)->down();

        foreach (self::SIGN_COLUMNS as $column) {
            $this->assertFalse(
                Schema::hasColumn('donaciones', $column),
                "Column {$column} was not dropped.",
            );
        }

        // The rollback must not touch unrelated schema.
        $this->assertTrue(Schema::hasColumn('donaciones', 'numero_donacion'));
    }

    public function test_donacion_persists_and_casts_clinical_signs_and_exposes_pressure(): void
    {
        $donacion = Donacion::create([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $this->tipo('SANGRE')->id,
            'fecha' => self::FECHA,
            'hemoglobina' => 13.2,
            'hematocrito' => 40.5,
            'plaquetas' => 250000,
            'presion_sistolica' => 120,
            'presion_diastolica' => 80,
            'frecuencia_cardiaca' => 72,
            'peso_donante' => 70.5,
        ]);

        $fresh = $donacion->fresh();

        $this->assertSame('13.20', $fresh->hemoglobina);
        $this->assertSame('40.50', $fresh->hematocrito);
        $this->assertSame(250000, $fresh->plaquetas);
        $this->assertSame(120, $fresh->presion_sistolica);
        $this->assertSame(80, $fresh->presion_diastolica);
        $this->assertSame(72, $fresh->frecuencia_cardiaca);
        $this->assertSame('70.50', $fresh->peso_donante);
        $this->assertSame('120/80', $fresh->presion_arterial);
        $this->assertArrayHasKey('presion_arterial', $fresh->toArray());
        $this->assertSame('120/80', $fresh->toArray()['presion_arterial']);
    }

    public function test_presion_arterial_is_null_when_only_one_value_is_present(): void
    {
        $sistolicaOnly = Donacion::create([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $this->tipo('SANGRE')->id,
            'fecha' => self::FECHA,
            'presion_sistolica' => 120,
        ]);

        $diastolicaOnly = Donacion::create([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $this->tipo('SANGRE')->id,
            'fecha' => self::FECHA,
            'presion_diastolica' => 80,
        ]);

        $this->assertNull($sistolicaOnly->fresh()->presion_arterial);
        $this->assertNull($diastolicaOnly->fresh()->presion_arterial);
    }

    public function test_legacy_donacion_reads_null_clinical_signs(): void
    {
        $donacion = Donacion::create([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $this->tipo('SANGRE')->id,
            'fecha' => self::FECHA,
        ]);

        $fresh = $donacion->fresh();

        foreach (self::SIGN_COLUMNS as $column) {
            $this->assertNull($fresh->{$column}, "Legacy {$column} should read null.");
        }

        $this->assertNull($fresh->presion_arterial);
    }

    public function test_es_aferesis_backfill_maps_catalog_codes(): void
    {
        $this->migration(self::AFERESIS_MIGRATION)->down();

        DB::table('tipos_donacion')->insert([
            ['nombre' => 'SANGRE', 'codigo' => 'SANGRE', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'PLASMA', 'codigo' => 'PLASMA', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'PLAQUETAS', 'codigo' => 'PLAQUETAS', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'CUSTOM', 'codigo' => 'CUSTOM', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->migration(self::AFERESIS_MIGRATION)->up();

        $flags = DB::table('tipos_donacion')->pluck('es_aferesis', 'codigo')->all();

        $this->assertEquals(0, $flags['SANGRE']);
        $this->assertEquals(1, $flags['PLASMA']);
        $this->assertEquals(1, $flags['PLAQUETAS']);
        $this->assertEquals(0, $flags['CUSTOM']);
    }

    public function test_es_aferesis_seeder_sets_flag_per_code(): void
    {
        (new TipoDonacionSeeder)->run();

        $this->assertFalse($this->tipo('SANGRE')->es_aferesis);
        $this->assertTrue($this->tipo('PLASMA')->es_aferesis);
        $this->assertTrue($this->tipo('PLAQUETAS')->es_aferesis);
    }

    public function test_es_aferesis_is_a_boolean_with_false_default(): void
    {
        $custom = TipoDonacion::create(['nombre' => 'CUSTOM', 'codigo' => 'CUSTOM']);
        $this->assertFalse($custom->fresh()->es_aferesis);

        $plasma = TipoDonacion::create(['nombre' => 'PLASMA_2', 'codigo' => 'PLASMA_2', 'es_aferesis' => true]);
        $this->assertTrue($plasma->fresh()->es_aferesis);
    }

    public function test_es_aferesis_rollback_drops_column(): void
    {
        $this->migration(self::AFERESIS_MIGRATION)->down();

        $this->assertFalse(Schema::hasColumn('tipos_donacion', 'es_aferesis'));
        $this->assertTrue(Schema::hasColumn('tipos_donacion', 'codigo'));
    }
}
