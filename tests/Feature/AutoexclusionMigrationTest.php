<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Autoexclusion;
use App\Models\ComponenteDonacion;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\TipoDonacion;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * WU-A surface: additive self-exclusion schema, per-unit discard columns and
 * the model contracts that expose them. No gating behaviour lives here.
 */
class AutoexclusionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const AUTOEXCLUSION_MIGRATION = 'migrations/2026_10_03_000010_create_autoexclusiones_table.php';

    private const DISCARD_MIGRATION = 'migrations/2026_10_03_000011_add_discard_state_to_componentes_donacion_table.php';

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

    private function donacion(?string $numero = null): Donacion
    {
        return Donacion::create([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $this->tipo('SANGRE')->id,
            'fecha' => now()->toDateString(),
            'numero_donacion' => $numero,
        ]);
    }

    private function usuario(): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);

        return Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);
    }

    public function test_autoexclusiones_table_has_expected_columns_without_paciente_id(): void
    {
        $this->assertTrue(Schema::hasTable('autoexclusiones'));

        foreach (['id', 'donacion_id', 'motivo', 'created_by', 'created_at', 'updated_at'] as $column) {
            $this->assertTrue(
                Schema::hasColumn('autoexclusiones', $column),
                "Missing autoexclusiones.{$column} column.",
            );
        }

        // Confidentiality (spec S5): the table must never key on the patient.
        $this->assertFalse(Schema::hasColumn('autoexclusiones', 'paciente_id'));
    }

    public function test_componentes_donacion_has_discard_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('componentes_donacion', 'descartado'));
        $this->assertTrue(Schema::hasColumn('componentes_donacion', 'motivo_descarte'));
    }

    public function test_new_component_defaults_to_not_discarded(): void
    {
        $donacion = $this->donacion();

        $componente = $donacion->componentes()->create(['tipo_id' => $this->tipo('SANGRE')->id]);

        $fresh = $componente->fresh();

        $this->assertFalse($fresh->descartado);
        $this->assertNull($fresh->motivo_descarte);
    }

    public function test_legacy_component_row_reads_false_and_null(): void
    {
        $donacion = $this->donacion();

        // Simulate a row persisted before the discard migration existed.
        $id = DB::table('componentes_donacion')->insertGetId([
            'donacion_id' => $donacion->id,
            'tipo_id' => $this->tipo('SANGRE')->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $legacy = ComponenteDonacion::findOrFail($id);

        $this->assertFalse($legacy->descartado);
        $this->assertNull($legacy->motivo_descarte);
    }

    public function test_scope_descartado_returns_only_discarded_components(): void
    {
        $donacion = $this->donacion();
        $tipo = $this->tipo('SANGRE');

        $donacion->componentes()->createMany([
            ['tipo_id' => $tipo->id, 'descartado' => true, 'motivo_descarte' => 'Autoexclusión del donante'],
            ['tipo_id' => $tipo->id, 'descartado' => false],
            ['tipo_id' => $tipo->id, 'descartado' => true, 'motivo_descarte' => 'Autoexclusión del donante'],
        ]);

        $descartados = ComponenteDonacion::descartado()->pluck('id');

        $this->assertSame(2, $descartados->count());
        $this->assertSame(1, ComponenteDonacion::where('descartado', false)->count());
    }

    public function test_discard_rollback_drops_only_new_columns(): void
    {
        $this->migration(self::DISCARD_MIGRATION)->down();

        $this->assertFalse(Schema::hasColumn('componentes_donacion', 'descartado'));
        $this->assertFalse(Schema::hasColumn('componentes_donacion', 'motivo_descarte'));

        // The rollback must not touch the pre-existing component schema.
        $this->assertTrue(Schema::hasColumn('componentes_donacion', 'donacion_id'));
        $this->assertTrue(Schema::hasColumn('componentes_donacion', 'tipo_id'));
        $this->assertTrue(Schema::hasColumn('componentes_donacion', 'vencimiento'));
        $this->assertTrue(Schema::hasColumn('componentes_donacion', 'peso'));
    }

    public function test_autoexclusion_is_unique_per_donation(): void
    {
        $donacion = $this->donacion();
        $usuario = $this->usuario();

        $donacion->autoexclusion()->create([
            'motivo' => 'Primera',
            'created_by' => $usuario->id,
        ]);

        $this->expectException(QueryException::class);

        Autoexclusion::create([
            'donacion_id' => $donacion->id,
            'motivo' => 'Segunda',
            'created_by' => $usuario->id,
        ]);
    }

    public function test_autoexclusion_relations_and_fillable(): void
    {
        $donacion = $this->donacion();

        $autoexclusion = Autoexclusion::create([
            'donacion_id' => $donacion->id,
            'motivo' => 'Autoexclusión del donante',
        ]);

        $this->assertTrue($autoexclusion->donacion->is($donacion));
        $this->assertTrue($donacion->fresh()->autoexclusion->is($autoexclusion));
        $this->assertNull($autoexclusion->created_by);
    }

    public function test_autoexclusion_rollback_drops_table(): void
    {
        $this->migration(self::AUTOEXCLUSION_MIGRATION)->down();

        $this->assertFalse(Schema::hasTable('autoexclusiones'));

        // The rollback must not touch unrelated schema.
        $this->assertTrue(Schema::hasTable('donaciones'));
    }
}
