<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\AutorizacionExtraordinaria;
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
 * WU-B2 surface: clinical signs flow through the HTTP boundary.
 *
 * Covers persistence, sourced Hb/weight warnings (409 then overridable 201),
 * non-overridable integrity errors (422), the apheresis prior-hemogram gate,
 * whole-blood exemption and legacy donations without signs.
 */
class ClinicalSignsTest extends TestCase
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

    /**
     * Whole-blood type by default; apheresis types are created explicitly.
     */
    private function tipo(string $codigo = 'SANGRE', bool $esAferesis = false): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(
            ['codigo' => $codigo],
            ['nombre' => $codigo, 'es_aferesis' => $esAferesis],
        );
    }

    private function payload(array $overrides = []): array
    {
        $tipo = $overrides['tipo_id'] ?? $this->tipo()->id;

        return array_merge([
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $tipo,
            'componentes' => [$tipo],
            'fecha' => self::FECHA,
        ], $overrides);
    }

    public function test_provided_signs_are_persisted_and_pressure_is_exposed(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload([
            'hemoglobina' => 13.2,
            'hematocrito' => 40.5,
            'plaquetas' => 250000,
            'presion_sistolica' => 120,
            'presion_diastolica' => 80,
            'frecuencia_cardiaca' => 72,
            'peso_donante' => 70.5,
        ]));

        $response->assertStatus(201);
        $response->assertJsonFragment(['presion_arterial' => '120/80']);

        $this->assertDatabaseHas('donaciones', [
            'hemoglobina' => 13.2,
            'hematocrito' => 40.5,
            'plaquetas' => 250000,
            'presion_sistolica' => 120,
            'presion_diastolica' => 80,
            'frecuencia_cardiaca' => 72,
            'peso_donante' => 70.5,
        ]);
    }

    public function test_sourced_boundary_values_are_accepted_without_warnings(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload([
            'hemoglobina' => 12.5,
            'peso_donante' => 50,
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseCount('donaciones', 1);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 0);
    }

    public function test_low_hemoglobin_without_override_returns_409_and_persists_nothing(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload([
            'hemoglobina' => 11.0,
        ]));

        $response->assertStatus(409);
        $codes = array_column($response->json('warnings'), 'code');
        $this->assertContains('HEMOGLOBINA_BAJA', $codes);
        $this->assertDatabaseCount('donaciones', 0);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 0);
    }

    public function test_low_hemoglobin_with_override_creates_donation_and_authorization(): void
    {
        $paciente = $this->paciente();
        $tipo = $this->tipo();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'hemoglobina' => 11.0,
            'forzar' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('donaciones', 1);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 1);
        $this->assertDatabaseHas('autorizaciones_extraordinarias', [
            'paciente_id' => $paciente->id,
            'usuario_id' => $this->staff->id,
        ]);

        // The authorization motivo is derived from the clinical warning code.
        $autorizacion = AutorizacionExtraordinaria::firstOrFail();
        $this->assertSame('Hemoglobina baja', $autorizacion->motivo);
    }

    public function test_low_weight_without_override_returns_409_with_peso_bajo(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload([
            'peso_donante' => 48,
        ]));

        $response->assertStatus(409);
        $codes = array_column($response->json('warnings'), 'code');
        $this->assertContains('PESO_BAJO', $codes);
        $this->assertDatabaseCount('donaciones', 0);
    }

    public function test_low_weight_with_override_creates_donation_and_authorization(): void
    {
        $paciente = $this->paciente();
        $tipo = $this->tipo();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'peso_donante' => 48,
            'forzar' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('donaciones', 1);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 1);
        $this->assertDatabaseHas('autorizaciones_extraordinarias', [
            'paciente_id' => $paciente->id,
            'usuario_id' => $this->staff->id,
        ]);
    }

    public function test_inverted_pressure_is_rejected_with_422_and_forzar_does_not_bypass(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload([
            'presion_sistolica' => 80,
            'presion_diastolica' => 120,
            'forzar' => true,
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseCount('donaciones', 0);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 0);
    }

    public function test_negative_sign_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload([
            'frecuencia_cardiaca' => -5,
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseCount('donaciones', 0);
    }

    public function test_non_numeric_sign_is_rejected_with_422(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload([
            'hemoglobina' => 'no-es-numero',
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['hemoglobina']);
        $this->assertDatabaseCount('donaciones', 0);
    }

    public function test_apheresis_type_missing_plaquetas_is_rejected_even_with_forzar(): void
    {
        $tipo = $this->tipo('PLASMA', true);
        $paciente = $this->paciente();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'hematocrito' => 40.5,
            'forzar' => true,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('donaciones', 0);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 0);
    }

    public function test_apheresis_type_missing_hematocrito_is_rejected_even_with_forzar(): void
    {
        $tipo = $this->tipo('PLASMA', true);
        $paciente = $this->paciente();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'plaquetas' => 250000,
            'forzar' => true,
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('donaciones', 0);
    }

    public function test_apheresis_type_with_complete_hemogram_is_accepted(): void
    {
        $tipo = $this->tipo('PLASMA', true);
        $paciente = $this->paciente();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'plaquetas' => 250000,
            'hematocrito' => 40.5,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('donaciones', 1);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 0);
    }

    public function test_whole_blood_type_is_exempt_from_the_hemogram_gate(): void
    {
        $tipo = $this->tipo('SANGRE', false);
        $paciente = $this->paciente();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('donaciones', 1);
    }

    public function test_apheresis_gate_follows_the_flag_not_the_code(): void
    {
        // PLASMA code but es_aferesis=false must NOT require a hemogram.
        $tipo = TipoDonacion::create(['nombre' => 'PLASMA', 'codigo' => 'PLASMA', 'es_aferesis' => false]);
        $paciente = $this->paciente();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('donaciones', 1);
    }

    public function test_capture_only_signs_are_persisted_but_never_gated(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload([
            'hemoglobina' => 13.0,
            'peso_donante' => 60,
            'hematocrito' => 1.0,
            'plaquetas' => 1,
            'presion_sistolica' => 300,
            'presion_diastolica' => 200,
            'frecuencia_cardiaca' => 250,
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseCount('donaciones', 1);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 0);
        $this->assertDatabaseHas('donaciones', [
            'hematocrito' => 1.0,
            'plaquetas' => 1,
            'frecuencia_cardiaca' => 250,
        ]);
    }

    public function test_legacy_donation_without_signs_is_still_accepted(): void
    {
        $response = $this->postJson('/api/donaciones', $this->payload());

        $response->assertStatus(201);

        $donacion = Donacion::firstOrFail();

        foreach ([
            'hemoglobina',
            'hematocrito',
            'plaquetas',
            'presion_sistolica',
            'presion_diastolica',
            'frecuencia_cardiaca',
            'peso_donante',
        ] as $column) {
            $this->assertNull($donacion->{$column}, "Legacy {$column} should be null.");
        }
    }
}
