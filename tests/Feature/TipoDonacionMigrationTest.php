<?php

namespace Tests\Feature;

use App\Models\TipoDonacion;
use Database\Seeders\TipoDonacionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Forward/backward behaviour of the additive tipos_donacion.codigo migration.
 */
class TipoDonacionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_03_000001_add_codigo_to_tipos_donacion_table.php';

    private function migration(): object
    {
        $path = database_path(self::MIGRATION);

        if (! file_exists($path)) {
            $this->fail('Tipo donacion codigo migration file has not been created yet: '.self::MIGRATION);
        }

        return require $path;
    }

    public function test_codigo_is_unique(): void
    {
        TipoDonacion::create(['nombre' => 'plasma_a', 'codigo' => 'PLASMA']);

        $this->expectException(QueryException::class);

        TipoDonacion::create(['nombre' => 'plasma_b', 'codigo' => 'PLASMA']);
    }

    public function test_codigo_cannot_be_null(): void
    {
        $this->expectException(QueryException::class);

        DB::table('tipos_donacion')->insert([
            'nombre' => 'sin_codigo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_backfill_assigns_known_code_from_nombre(): void
    {
        $this->migration()->down();

        $legacyId = DB::table('tipos_donacion')->insertGetId([
            'nombre' => 'PLASMA',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unknownId = DB::table('tipos_donacion')->insertGetId([
            'nombre' => 'personalizado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->migration()->up();

        $this->assertSame('PLASMA', DB::table('tipos_donacion')->where('id', $legacyId)->value('codigo'));
        $this->assertSame('PERSONALIZADO', DB::table('tipos_donacion')->where('id', $unknownId)->value('codigo'));
        $this->assertSame(0, DB::table('tipos_donacion')->whereNull('codigo')->count());
    }

    public function test_backfill_dedupes_derived_codes(): void
    {
        $this->migration()->down();

        DB::table('tipos_donacion')->insert([
            ['nombre' => 'duplicado', 'created_at' => now(), 'updated_at' => now()],
            ['nombre' => 'duplicado', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->migration()->up();

        $codigos = DB::table('tipos_donacion')->orderBy('id')->pluck('codigo')->all();

        $this->assertSame(['DUPLICADO', 'DUPLICADO_2'], $codigos);
        $this->assertSame(count($codigos), count(array_unique($codigos)));
    }

    public function test_seeder_codes_the_full_catalog_once(): void
    {
        (new TipoDonacionSeeder)->run();

        $codigos = TipoDonacion::orderBy('codigo')->pluck('codigo')->all();

        $this->assertSame(['PLAQUETAS', 'PLASMA', 'SANGRE'], $codigos);
        $this->assertSame(0, TipoDonacion::whereNull('codigo')->count());

        foreach (['SANGRE', 'PLASMA', 'PLAQUETAS'] as $codigo) {
            $this->assertSame(1, TipoDonacion::where('codigo', $codigo)->count());
        }
    }

    public function test_rollback_drops_codigo_column(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('tipos_donacion', 'codigo'));
    }
}
