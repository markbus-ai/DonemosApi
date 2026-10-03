<?php

namespace Tests\Feature;

use App\Models\Sede;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Forward/backward behaviour of the additive sedes.codigo_localidad migration.
 */
class SedeCodigoLocalidadMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_03_000003_add_codigo_localidad_to_sedes_table.php';

    private function migration(): object
    {
        $path = database_path(self::MIGRATION);

        if (! file_exists($path)) {
            $this->fail('Sede codigo_localidad migration file has not been created yet: '.self::MIGRATION);
        }

        return require $path;
    }

    public function test_central_sede_is_backfilled_with_code_one(): void
    {
        $central = Sede::where('nombre', 'Sede Central')->firstOrFail();

        $this->assertSame('1', $central->codigo_localidad);
    }

    public function test_codigo_localidad_is_unique(): void
    {
        Sede::create(['nombre' => 'Pinamar', 'codigo_localidad' => '003']);
        $this->assertDatabaseHas('sedes', ['codigo_localidad' => '003']);

        $this->expectException(QueryException::class);

        Sede::create(['nombre' => 'Mar de Ajo', 'codigo_localidad' => '003']);
    }

    public function test_null_codigo_localidad_is_allowed(): void
    {
        $sede = Sede::create(['nombre' => 'Sin codigo', 'codigo_localidad' => null]);

        $this->assertNull($sede->fresh()->codigo_localidad);
    }

    public function test_rollback_drops_codigo_column(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('sedes', 'codigo_localidad'));
    }

    public function test_up_recreates_and_backfills_codigo_column(): void
    {
        $this->migration()->down();
        $this->assertFalse(Schema::hasColumn('sedes', 'codigo_localidad'));

        $this->migration()->up();

        $this->assertTrue(Schema::hasColumn('sedes', 'codigo_localidad'));
        $this->assertSame('1', Sede::where('nombre', 'Sede Central')->value('codigo_localidad'));
    }
}
