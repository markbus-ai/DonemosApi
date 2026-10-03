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
use Tests\TestCase;

class DonacionTest extends TestCase
{
    use RefreshDatabase;

    private function createAptitud(string $tipo = 'APTO'): Aptitud
    {
        return Aptitud::create(['tipo' => $tipo]);
    }

    private function createTipo(string $nombre = 'PLASMA'): TipoDonacion
    {
        return TipoDonacion::create(['nombre' => $nombre]);
    }

    private function createPaciente(Aptitud $aptitud): Paciente
    {
        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ]);
    }

    private function createUsuario(): Usuario
    {
        $rol = Rol::create(['nombre' => 'ADMIN']);
        return Usuario::create([
            'username' => fake()->unique()->userName(),
            'password_hash' => 'hash_test',
            'rol_id' => $rol->id,
        ]);
    }

    public function test_sin_warning_crea_donacion_201(): void
    {
        $aptitud = $this->createAptitud();
        $tipo = $this->createTipo('PLASMA');
        $paciente = $this->createPaciente($aptitud);
        $this->createUsuario(); // para que DonacionService tenga fallback usuario_id si fuerza (no usado aquí pero asegura DB)

        $payload = [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->toDateString(),
        ];

        $response = $this->postJson('/api/donaciones', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'paciente_id', 'tipo_id', 'fecha']);

        $this->assertDatabaseHas('donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
        ]);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 0);
    }

    public function test_con_warning_sin_forzar_no_crea_donacion_retorna_409_con_warnings(): void
    {
        $this->markTestSkipped('espera normativa oficial');

        // TODO: DonationRules actualmente retorna allowed true siempre (TODO pendiente).
        // Esqueleto para cuando la normativa oficial esté implementada.
        // El historial debe violar la regla para que DonationRules devuelva warnings.
        //
        // Flujo esperado:
        // 1. Crear historial que viole intervalo o límite anual (valores oficiales)
        // 2. POST /api/donaciones SIN forzar
        // 3. Esperar 409 con warnings, sin crear donación

        $aptitud = $this->createAptitud();
        $tipo = $this->createTipo('PLASMA');
        $paciente = $this->createPaciente($aptitud);
        $this->createUsuario();

        // Historial que violará la normativa (placeholder: donación hace 5 días)
        Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->subDays(5)->toDateString(),
        ]);

        $payload = [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->toDateString(),
            // sin forzar
        ];

        $response = $this->postJson('/api/donaciones', $payload);

        $response->assertStatus(409);
        $response->assertJsonStructure(['warnings']);
        $warnings = $response->json('warnings');
        $this->assertNotEmpty($warnings);
        $codes = array_column($warnings, 'code');
        $this->assertContains('INTERVALO_MINIMO', $codes); // TODO: confirmar WARNING_INTERVALO oficial

        // No debe crear donación adicional
        $this->assertDatabaseCount('donaciones', 1); // solo la histórica
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 0);
    }

    public function test_con_warning_con_forzar_crea_autorizacion_extraordinaria_y_donacion_201(): void
    {
        $this->markTestSkipped('espera normativa oficial');

        // TODO: idem anterior, pero con forzar:true debe crear autorización + donación
        // Esqueleto:
        // 1. Crear historial violatorio
        // 2. POST /api/donaciones con forzar:true
        // 3. Esperar 201, verificar donación y autorización en DB

        $aptitud = $this->createAptitud();
        $tipo = $this->createTipo('PLASMA');
        $paciente = $this->createPaciente($aptitud);
        $usuario = $this->createUsuario();

        Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->subDays(5)->toDateString(),
        ]);

        $payload = [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->toDateString(),
            'forzar' => true,
        ];

        $response = $this->postJson('/api/donaciones', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'paciente_id', 'tipo_id', 'fecha']);

        $this->assertDatabaseCount('donaciones', 2); // histórica + nueva
        $this->assertDatabaseHas('donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->toDateString(),
        ]);

        $this->assertDatabaseCount('autorizaciones_extraordinarias', 1);
        $this->assertDatabaseHas('autorizaciones_extraordinarias', [
            'paciente_id' => $paciente->id,
            'usuario_id' => $usuario->id,
        ]);
    }
}
