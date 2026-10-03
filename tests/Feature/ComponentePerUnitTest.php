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
 * Per-unit expiry and weight on the component row (the traceable unit).
 */
class ComponentePerUnitTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_10_03_000007_add_per_unit_fields_to_componentes_donacion_table.php';

    private const FECHA = '2026-05-04';

    private Usuario $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = $this->createUsuario();
        Sanctum::actingAs($this->staff, ['*'], 'staff');
    }

    private function createUsuario(): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);

        return Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);
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

    public function test_each_component_row_keeps_its_own_expiry_and_weight(): void
    {
        $plasma = $this->tipo('PLASMA');
        $plaquetas = $this->tipo('PLAQUETAS');

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $plasma->id,
            'fecha' => self::FECHA,
            'componentes' => [
                ['tipo_id' => $plasma->id, 'vencimiento' => '2026-06-01', 'peso' => 250.5],
                ['tipo_id' => $plaquetas->id, 'vencimiento' => '2026-06-10', 'peso' => 60.25],
            ],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('componentes_donacion', [
            'tipo_id' => $plasma->id,
            'vencimiento' => '2026-06-01',
            'peso' => 250.5,
        ]);
        $this->assertDatabaseHas('componentes_donacion', [
            'tipo_id' => $plaquetas->id,
            'vencimiento' => '2026-06-10',
            'peso' => 60.25,
        ]);
    }

    public function test_legacy_component_rows_keep_null_per_unit_fields(): void
    {
        $plasma = $this->tipo('PLASMA');
        $donacion = Donacion::create([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $plasma->id,
            'fecha' => self::FECHA,
        ]);

        $componente = $donacion->componentes()->create(['tipo_id' => $plasma->id]);

        $this->assertNull($componente->fresh()->vencimiento);
        $this->assertNull($componente->fresh()->peso);
    }

    public function test_invalid_expiry_is_rejected_with_nothing_persisted(): void
    {
        $plasma = $this->tipo('PLASMA');

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $plasma->id,
            'fecha' => self::FECHA,
            'componentes' => [
                ['tipo_id' => $plasma->id, 'vencimiento' => 'not-a-date'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['componentes.0.vencimiento']);
        $this->assertDatabaseCount('donaciones', 0);
        $this->assertDatabaseCount('componentes_donacion', 0);
    }

    public function test_invalid_weight_is_rejected(): void
    {
        $plasma = $this->tipo('PLASMA');

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $plasma->id,
            'fecha' => self::FECHA,
            'componentes' => [
                ['tipo_id' => $plasma->id, 'peso' => 'heavy'],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['componentes.0.peso']);
    }

    public function test_rollback_drops_per_unit_columns(): void
    {
        $path = database_path(self::MIGRATION);

        if (! file_exists($path)) {
            $this->fail('Per-unit migration file has not been created yet: '.self::MIGRATION);
        }

        (require $path)->down();

        $this->assertFalse(Schema::hasColumn('componentes_donacion', 'vencimiento'));
        $this->assertFalse(Schema::hasColumn('componentes_donacion', 'peso'));
    }
}
