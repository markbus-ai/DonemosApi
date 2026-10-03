<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\ComponenteDonacion;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\TipoDonacion;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DonacionTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = $this->createUsuario();
        Sanctum::actingAs($this->staff, ['*'], 'staff');
    }

    private function createAptitud(string $tipo = 'APTO'): Aptitud
    {
        return Aptitud::create(['tipo' => $tipo]);
    }

    private function createTipo(string $codigo = 'PLASMA'): TipoDonacion
    {
        return TipoDonacion::create(['nombre' => $codigo, 'codigo' => $codigo]);
    }

    /**
     * @return array{SANGRE: TipoDonacion, PLASMA: TipoDonacion, PLAQUETAS: TipoDonacion}
     */
    private function createTiposCatalogo(): array
    {
        return [
            'SANGRE' => $this->createTipo('SANGRE'),
            'PLASMA' => $this->createTipo('PLASMA'),
            'PLAQUETAS' => $this->createTipo('PLAQUETAS'),
        ];
    }

    private function pacienteConAptitud(): Paciente
    {
        return $this->createPaciente($this->createAptitud());
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
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);
    }

    public function test_sin_warning_crea_donacion_201(): void
    {
        $aptitud = $this->createAptitud();
        $tipo = $this->createTipo('PLASMA');
        $paciente = $this->createPaciente($aptitud);

        $payload = [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => now()->toDateString(),
        ];

        $response = $this->postJson('/api/donaciones', $payload);

        $response->assertStatus(201);
        $response->assertJsonStructure(['id', 'paciente_id', 'tipo_id', 'fecha']);
        $response->assertJsonCount(1, 'componentes');

        $this->assertDatabaseHas('donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
        ]);
        $this->assertDatabaseCount('componentes_donacion', 1);
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
            'componentes' => [$tipo->id],
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
            'componentes' => [$tipo->id],
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

    public function test_donacion_con_un_componente_sangre_persiste_una_fila(): void
    {
        $tipos = $this->createTiposCatalogo();
        $paciente = $this->pacienteConAptitud();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipos['SANGRE']->id,
            'componentes' => [$tipos['SANGRE']->id],
            'fecha' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(1, 'componentes');
        $this->assertDatabaseCount('componentes_donacion', 1);
        $this->assertDatabaseHas('componentes_donacion', ['tipo_id' => $tipos['SANGRE']->id]);
    }

    public function test_donacion_con_plasma_y_tres_plaquetas_persiste_cuatro_filas(): void
    {
        $tipos = $this->createTiposCatalogo();
        $paciente = $this->pacienteConAptitud();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipos['PLASMA']->id,
            'componentes' => [
                $tipos['PLASMA']->id,
                $tipos['PLAQUETAS']->id,
                $tipos['PLAQUETAS']->id,
                $tipos['PLAQUETAS']->id,
            ],
            'fecha' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(4, 'componentes');
        $this->assertDatabaseCount('componentes_donacion', 4);
        $this->assertSame(3, ComponenteDonacion::where('tipo_id', $tipos['PLAQUETAS']->id)->count());
    }

    public function test_donacion_con_tres_plaquetas_persiste_tres_filas(): void
    {
        $tipos = $this->createTiposCatalogo();
        $paciente = $this->pacienteConAptitud();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipos['PLAQUETAS']->id,
            'componentes' => [
                $tipos['PLAQUETAS']->id,
                $tipos['PLAQUETAS']->id,
                $tipos['PLAQUETAS']->id,
            ],
            'fecha' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
        $response->assertJsonCount(3, 'componentes');
        $this->assertDatabaseCount('componentes_donacion', 3);
    }

    public function test_cuatro_plaquetas_rechazado_422_sin_persistir(): void
    {
        $tipos = $this->createTiposCatalogo();
        $paciente = $this->pacienteConAptitud();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipos['PLAQUETAS']->id,
            'componentes' => array_fill(0, 4, $tipos['PLAQUETAS']->id),
            'fecha' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('donaciones', 0);
        $this->assertDatabaseCount('componentes_donacion', 0);
    }

    public function test_dos_plasma_rechazado_422_sin_persistir(): void
    {
        $tipos = $this->createTiposCatalogo();
        $paciente = $this->pacienteConAptitud();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipos['PLASMA']->id,
            'componentes' => [$tipos['PLASMA']->id, $tipos['PLASMA']->id],
            'fecha' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('donaciones', 0);
        $this->assertDatabaseCount('componentes_donacion', 0);
    }

    public function test_componentes_vacios_rechazado_422(): void
    {
        $tipos = $this->createTiposCatalogo();
        $paciente = $this->pacienteConAptitud();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipos['SANGRE']->id,
            'componentes' => [],
            'fecha' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['componentes']);
        $this->assertDatabaseCount('donaciones', 0);
    }

    public function test_componente_fuera_del_catalogo_rechazado_422(): void
    {
        $tipos = $this->createTiposCatalogo();
        $paciente = $this->pacienteConAptitud();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipos['SANGRE']->id,
            'componentes' => [999999],
            'fecha' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['componentes.0']);
        $this->assertDatabaseCount('donaciones', 0);
    }

    public function test_codigo_desconocido_rechazado_422_sin_persistir(): void
    {
        $tipos = $this->createTiposCatalogo();
        $raro = TipoDonacion::create(['nombre' => 'raro', 'codigo' => 'DESCONOCIDO']);
        $paciente = $this->pacienteConAptitud();

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipos['SANGRE']->id,
            'componentes' => [$raro->id],
            'fecha' => now()->toDateString(),
        ]);

        $response->assertStatus(422);
        $this->assertDatabaseCount('donaciones', 0);
        $this->assertDatabaseCount('componentes_donacion', 0);
    }

    public function test_donacion_legacy_sin_componentes_lee_coleccion_vacia(): void
    {
        $tipo = $this->createTipo('PLASMA');
        $paciente = $this->pacienteConAptitud();

        $donacion = Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->toDateString(),
        ]);

        $this->assertCount(0, $donacion->fresh()->componentes);
    }
}
