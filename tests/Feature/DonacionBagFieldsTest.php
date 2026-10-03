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
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Collection-level bag fields, double-label confirmation and operator
 * attribution on donaciones.
 */
class DonacionBagFieldsTest extends TestCase
{
    use RefreshDatabase;

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

    private function tipo(): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => 'PLASMA'], ['nombre' => 'PLASMA']);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $this->tipo()->id,
            'componentes' => [$this->tipo()->id],
            'fecha' => self::FECHA,
        ], $overrides);
    }

    public function test_valid_bag_fields_are_persisted(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload([
            'tipo_bolsa' => 'doble',
            'anticoagulante' => 'CPD',
            'lote' => 'LOTE-1',
            'tubuladura' => 'TUB-1',
            'brazo' => 'izquierdo',
            'dificultad' => 'normal',
            'doble_etiqueta' => true,
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('donaciones', [
            'tipo_bolsa' => 'doble',
            'anticoagulante' => 'CPD',
            'lote' => 'LOTE-1',
            'tubuladura' => 'TUB-1',
            'brazo' => 'izquierdo',
            'dificultad' => 'normal',
            'doble_etiqueta' => true,
        ]);
    }

    public function test_invalid_bag_type_is_rejected_with_nothing_persisted(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload(['tipo_bolsa' => 'quintuple_x']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['tipo_bolsa']);
        $this->assertDatabaseCount('donaciones', 0);
        $this->assertDatabaseCount('componentes_donacion', 0);
    }

    public function test_invalid_arm_is_rejected(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload(['brazo' => 'arriba']));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['brazo']);
    }

    public function test_double_label_defaults_to_false_and_true_is_persisted(): void
    {
        $this->postJson('/api/donaciones', $this->payload())->assertStatus(201);
        $this->postJson('/api/donaciones', $this->payload(['doble_etiqueta' => true]))->assertStatus(201);

        $this->assertSame(1, Donacion::where('doble_etiqueta', false)->count());
        $this->assertSame(1, Donacion::where('doble_etiqueta', true)->count());
    }

    public function test_operator_comes_from_staff_and_client_payload_is_ignored(): void
    {
        $otro = $this->createUsuario();

        $response = $this->postJson('/api/donaciones', $this->payload(['operador_id' => $otro->id]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('donaciones', ['operador_id' => $this->staff->id]);
        $this->assertDatabaseMissing('donaciones', ['operador_id' => $otro->id]);
    }
}
