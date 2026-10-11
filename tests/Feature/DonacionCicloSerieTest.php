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
 * Manual donation-cycle metadata (`ciclo` / `serie` / `donacion`) stored per
 * donation. The staff decides these values; the system never computes, copies
 * or increments them from the patient counters or the donation history.
 * `donacion` is the ordinal WITHIN the serie, distinct from `numero_donacion`
 * (the traceability code).
 */
class DonacionCicloSerieTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_03_000017_add_ciclo_serie_donacion_to_donaciones_table.php';

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

    private function tipo(string $codigo = 'SANGRE'): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => $codigo], ['nombre' => $codigo]);
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

    private function payload(Paciente $paciente, TipoDonacion $tipo, array $overrides = []): array
    {
        return array_merge([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => now()->toDateString(),
        ], $overrides);
    }

    public function test_ciclo_serie_donacion_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumn('donaciones', 'ciclo'));
        $this->assertTrue(Schema::hasColumn('donaciones', 'serie'));
        $this->assertTrue(Schema::hasColumn('donaciones', 'donacion'));
    }

    public function test_rollback_drops_only_added_columns(): void
    {
        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('donaciones', 'ciclo'));
        $this->assertFalse(Schema::hasColumn('donaciones', 'serie'));
        $this->assertFalse(Schema::hasColumn('donaciones', 'donacion'));
        $this->assertTrue(Schema::hasColumn('donaciones', 'numero_donacion'));
    }

    public function test_creating_donation_with_cycle_fields_persists_them(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente();

        $response = $this->postJson('/api/donaciones', $this->payload($paciente, $tipo, [
            'ciclo' => 2,
            'serie' => 3,
            'donacion' => 5,
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('donaciones', [
            'paciente_id' => $paciente->id,
            'ciclo' => 2,
            'serie' => 3,
            'donacion' => 5,
        ]);
    }

    public function test_creating_donation_without_cycle_fields_keeps_them_null(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente();

        $response = $this->postJson('/api/donaciones', $this->payload($paciente, $tipo));

        $response->assertStatus(201);
        $this->assertNull($response->json('ciclo'));
        $this->assertNull($response->json('serie'));
        $this->assertNull($response->json('donacion'));

        $this->assertDatabaseHas('donaciones', [
            'paciente_id' => $paciente->id,
            'ciclo' => null,
            'serie' => null,
            'donacion' => null,
        ]);
    }

    public function test_resource_exposes_cycle_fields(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente();
        $donacion = Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->toDateString(),
            'ciclo' => 4,
            'serie' => 2,
            'donacion' => 7,
        ]);

        $response = $this->getJson('/api/donaciones');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.id', $donacion->id);
        $response->assertJsonPath('data.0.ciclo', 4);
        $response->assertJsonPath('data.0.serie', 2);
        $response->assertJsonPath('data.0.donacion', 7);
    }

    public function test_cycle_fields_below_one_are_rejected_422(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente();

        $response = $this->postJson('/api/donaciones', $this->payload($paciente, $tipo, [
            'ciclo' => 0,
            'serie' => -1,
            'donacion' => 0,
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ciclo', 'serie', 'donacion']);
        $this->assertDatabaseCount('donaciones', 0);
    }

    public function test_non_integer_cycle_fields_are_rejected_422(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente();

        $response = $this->postJson('/api/donaciones', $this->payload($paciente, $tipo, [
            'ciclo' => 'abc',
            'serie' => 1.5,
            'donacion' => 'x',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['ciclo', 'serie', 'donacion']);
        $this->assertDatabaseCount('donaciones', 0);
    }

    public function test_creating_donation_does_not_touch_patient_counters(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente([
            'ciclo_actual' => 2,
            'serie_actual' => 3,
            'donacion_actual' => 5,
        ]);

        $response = $this->postJson('/api/donaciones', $this->payload($paciente, $tipo, [
            'ciclo' => 9,
            'serie' => 9,
            'donacion' => 9,
        ]));

        $response->assertStatus(201);

        $fresh = $paciente->fresh();
        $this->assertSame(2, $fresh->ciclo_actual);
        $this->assertSame(3, $fresh->serie_actual);
        $this->assertSame(5, $fresh->donacion_actual);
    }
}
