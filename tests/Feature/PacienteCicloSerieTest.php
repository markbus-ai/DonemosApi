<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\TipoDonacion;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Patient cycle counters (`ciclo_actual` / `serie_actual` /
 * `donacion_actual`). The staff sets and resets them freely through
 * PATCH /api/pacientes/{dni}; a manual PATCH never touches the donations
 * history (it is the donation flow that re-syncs these counters).
 */
class PacienteCicloSerieTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_03_000018_add_ciclo_serie_donacion_actual_to_pacientes_table.php';

    protected function setUp(): void
    {
        parent::setUp();

        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);
        $staff = Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);

        Sanctum::actingAs($staff, ['*'], 'staff');
    }

    private function migration(): object
    {
        $path = database_path(self::MIGRATION);

        if (! file_exists($path)) {
            $this->fail('Migration file has not been created yet: '.self::MIGRATION);
        }

        return require $path;
    }

    private function paciente(array $overrides = []): Paciente
    {
        $aptitud = Aptitud::firstOrCreate(['tipo' => 'APTO']);

        return Paciente::create(array_merge([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ], $overrides));
    }

    public function test_ciclo_actual_serie_actual_donacion_actual_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('pacientes', 'ciclo_actual'));
        $this->assertTrue(Schema::hasColumn('pacientes', 'serie_actual'));
        $this->assertTrue(Schema::hasColumn('pacientes', 'donacion_actual'));
    }

    public function test_rollback_drops_only_added_columns(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('pacientes', 'ciclo_actual'));
        $this->assertFalse(Schema::hasColumn('pacientes', 'serie_actual'));
        $this->assertFalse(Schema::hasColumn('pacientes', 'donacion_actual'));
        $this->assertTrue(Schema::hasColumn('pacientes', 'dni'));
    }

    public function test_patch_updates_all_counters(): void
    {
        $paciente = $this->paciente();

        $response = $this->patchJson('/api/pacientes/'.$paciente->dni, [
            'ciclo_actual' => 2,
            'serie_actual' => 3,
            'donacion_actual' => 5,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('pacientes', [
            'id' => $paciente->id,
            'ciclo_actual' => 2,
            'serie_actual' => 3,
            'donacion_actual' => 5,
        ]);
    }

    public function test_patch_can_reset_counters_to_null(): void
    {
        $paciente = $this->paciente([
            'ciclo_actual' => 2,
            'serie_actual' => 3,
            'donacion_actual' => 5,
        ]);

        $response = $this->patchJson('/api/pacientes/'.$paciente->dni, [
            'ciclo_actual' => null,
            'serie_actual' => null,
            'donacion_actual' => null,
        ]);

        $response->assertStatus(200);
        $fresh = $paciente->fresh();
        $this->assertNull($fresh->ciclo_actual);
        $this->assertNull($fresh->serie_actual);
        $this->assertNull($fresh->donacion_actual);
    }

    public function test_resource_exposes_counters(): void
    {
        $paciente = $this->paciente([
            'ciclo_actual' => 4,
            'serie_actual' => 2,
            'donacion_actual' => 7,
        ]);

        $response = $this->getJson('/api/pacientes');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.id', $paciente->id);
        $response->assertJsonPath('data.0.ciclo_actual', 4);
        $response->assertJsonPath('data.0.serie_actual', 2);
        $response->assertJsonPath('data.0.donacion_actual', 7);
    }

    public function test_counters_below_one_are_rejected_422(): void
    {
        $paciente = $this->paciente();

        $response = $this->patchJson('/api/pacientes/'.$paciente->dni, [
            'ciclo_actual' => 0,
            'serie_actual' => -2,
            'donacion_actual' => 0,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ciclo_actual', 'serie_actual', 'donacion_actual']);
    }

    public function test_non_integer_counters_are_rejected_422(): void
    {
        $paciente = $this->paciente();

        $response = $this->patchJson('/api/pacientes/'.$paciente->dni, [
            'ciclo_actual' => 'abc',
            'serie_actual' => 1.5,
            'donacion_actual' => 'x',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ciclo_actual', 'serie_actual', 'donacion_actual']);
    }

    public function test_updating_counters_does_not_touch_donations(): void
    {
        $paciente = $this->paciente(['ciclo_actual' => 1, 'serie_actual' => 1, 'donacion_actual' => 1]);
        $tipo = TipoDonacion::firstOrCreate(['codigo' => 'SANGRE'], ['nombre' => 'SANGRE']);

        $donacion = Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->toDateString(),
            'ciclo' => 1,
            'serie' => 1,
            'donacion' => 1,
        ]);

        $response = $this->patchJson('/api/pacientes/'.$paciente->dni, [
            'ciclo_actual' => 9,
            'serie_actual' => 2,
            'donacion_actual' => 4,
        ]);

        $response->assertStatus(200);

        $fresh = $donacion->fresh();
        $this->assertSame(1, $fresh->ciclo);
        $this->assertSame(1, $fresh->serie);
        $this->assertSame(1, $fresh->donacion);
    }
}
