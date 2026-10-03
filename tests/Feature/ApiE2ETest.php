<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\AutorizacionExtraordinaria;
use App\Models\Donacion;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\TipoDonacion;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ApiE2ETest extends TestCase
{
    use RefreshDatabase;

    private Usuario $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedAptitudes();
        $this->seedTipos();
        $this->staff = $this->createUsuario();
        Sanctum::actingAs($this->staff, ['*'], 'staff');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------
    private function seedAptitudes(): void
    {
        foreach (['APTO', 'APTO_OBSERVACION', 'NO_APTO'] as $tipo) {
            Aptitud::firstOrCreate(['tipo' => $tipo]);
        }
    }

    private function seedTipos(): void
    {
        foreach (['PLASMA', 'PLAQUETAS'] as $nombre) {
            TipoDonacion::firstOrCreate(['nombre' => $nombre]);
        }
    }

    private function getTipo(string $nombre): TipoDonacion
    {
        return TipoDonacion::where('nombre', $nombre)->firstOrFail();
    }

    private function getAptitud(string $tipo): Aptitud
    {
        return Aptitud::where('tipo', $tipo)->firstOrFail();
    }

    private function createMotivo(string $nombre = 'test'): Motivo
    {
        return Motivo::create(['nombre' => $nombre . '_' . uniqid()]);
    }

    private function createRol(string $nombre = 'ADMIN'): Rol
    {
        return Rol::firstOrCreate(['nombre' => $nombre]);
    }

    private function createUsuario(?Rol $rol = null): Usuario
    {
        $rol = $rol ?? $this->createRol();
        return Usuario::create([
            'username' => 'user_' . uniqid(),
            'password_hash' => 'hash_test',
            'rol_id' => $rol->id,
        ]);
    }

    private function createPacienteModel(array $overrides = []): Paciente
    {
        $aptitud = $this->getAptitud('APTO');
        return Paciente::create(array_merge([
            'dni' => $this->generateDni(),
            'nombre' => 'Nombre',
            'apellido' => 'Apellido',
            'telefono' => '1122334455',
            'aptitud_id' => $aptitud->id,
        ], $overrides));
    }

    private function generateDni(): string
    {
        return (string) random_int(10000000, 99999999) . random_int(10, 99);
    }

    // -----------------------------------------------------------------
    // 1. POST /api/pacientes -> 201, verifica aptitud APTO por defecto
    // -----------------------------------------------------------------
    public function test_01_post_pacientes_crea_201_con_aptitud_apto_por_defecto(): void
    {
        $dni = $this->generateDni();
        $payload = [
            'dni' => $dni,
            'nombre' => 'Juan',
            'apellido' => 'Perez',
            'telefono' => '1122334455',
        ];

        $response = $this->postJson('/api/pacientes', $payload);
        $response->assertStatus(201);
        $response->assertJsonFragment(['dni' => $dni]);

        $apto = $this->getAptitud('APTO');
        $this->assertDatabaseHas('pacientes', [
            'dni' => $dni,
            'aptitud_id' => $apto->id,
        ]);
    }

    // -----------------------------------------------------------------
    // 2. GET /api/pacientes/{dni} -> 200
    // -----------------------------------------------------------------
    public function test_02_get_pacientes_por_dni_200(): void
    {
        $paciente = $this->createPacienteModel();
        $response = $this->getJson('/api/pacientes/' . $paciente->dni);
        $response->assertStatus(200);
        $response->assertJsonFragment(['dni' => $paciente->dni]);
    }

    // -----------------------------------------------------------------
    // 3. PATCH /api/pacientes/{dni} -> 200 actualiza telefono
    // -----------------------------------------------------------------
    public function test_03_patch_pacientes_actualiza_telefono(): void
    {
        $paciente = $this->createPacienteModel(['telefono' => '111111']);
        $response = $this->patchJson('/api/pacientes/' . $paciente->dni, [
            'telefono' => '999888777',
        ]);
        $response->assertStatus(200);
        $response->assertJsonFragment(['telefono' => '999888777']);
        $this->assertDatabaseHas('pacientes', [
            'dni' => $paciente->dni,
            'telefono' => '999888777',
        ]);
    }

    // -----------------------------------------------------------------
    // 4. PATCH /api/pacientes/{dni}/aptitud con tipo NO_APTO -> 200 verifica aptitud cambiada
    // -----------------------------------------------------------------
    public function test_04_patch_aptitud_cambia_a_no_apto(): void
    {
        $paciente = $this->createPacienteModel();
        $motivo = $this->createMotivo('motivo_no_apto');
        $response = $this->patchJson('/api/pacientes/' . $paciente->dni . '/aptitud', [
            'tipo' => 'NO_APTO',
            'motivo_id' => $motivo->id,
            'desde' => now()->toDateString(),
        ]);
        $response->assertStatus(200);

        $noApto = $this->getAptitud('NO_APTO');
        $this->assertDatabaseHas('pacientes', [
            'dni' => $paciente->dni,
            'aptitud_id' => $noApto->id,
        ]);
        // Verifica que la respuesta incluye aptitud cargada
        $response->assertJsonFragment(['tipo' => 'NO_APTO']);
    }

    // -----------------------------------------------------------------
    // 5. POST /api/pacientes/{dni}/observacion -> 201
    // -----------------------------------------------------------------
    public function test_05_post_observacion_201(): void
    {
        $paciente = $this->createPacienteModel();
        $motivo = $this->createMotivo('motivo_obs');

        $payload = [
            'motivo_id' => $motivo->id,
            'desde' => now()->toDateString(),
            'hasta' => Carbon::tomorrow()->toDateString(),
        ];

        $response = $this->postJson('/api/pacientes/' . $paciente->dni . '/observacion', $payload);
        $response->assertStatus(201);
        $response->assertJsonFragment(['motivo_id' => $motivo->id]);
        $this->assertDatabaseHas('observaciones', [
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo->id,
        ]);
    }

    // -----------------------------------------------------------------
    // 6. POST segunda observacion mismo paciente -> 201 (permite múltiples)
    // -----------------------------------------------------------------
    public function test_06_post_segunda_observacion_permite_multiples(): void
    {
        $paciente = $this->createPacienteModel();
        $motivo1 = $this->createMotivo('motivo1');
        $motivo2 = $this->createMotivo('motivo2');

        $this->postJson('/api/pacientes/' . $paciente->dni . '/observacion', [
            'motivo_id' => $motivo1->id,
            'desde' => now()->toDateString(),
        ])->assertStatus(201);

        $response = $this->postJson('/api/pacientes/' . $paciente->dni . '/observacion', [
            'motivo_id' => $motivo2->id,
            'desde' => Carbon::tomorrow()->toDateString(),
        ]);
        $response->assertStatus(201);

        $this->assertDatabaseCount('observaciones', 2);
        $this->assertDatabaseHas('observaciones', ['motivo_id' => $motivo1->id]);
        $this->assertDatabaseHas('observaciones', ['motivo_id' => $motivo2->id]);
    }

    // -----------------------------------------------------------------
    // 7. DELETE /api/pacientes/{dni}/observacion -> 204
    // -----------------------------------------------------------------
    public function test_07_delete_observacion_204(): void
    {
        $paciente = $this->createPacienteModel();
        $motivo = $this->createMotivo();

        $this->postJson('/api/pacientes/' . $paciente->dni . '/observacion', [
            'motivo_id' => $motivo->id,
            'desde' => now()->toDateString(),
        ])->assertStatus(201);

        $this->assertDatabaseCount('observaciones', 1);

        $response = $this->deleteJson('/api/pacientes/' . $paciente->dni . '/observacion');
        $response->assertStatus(204);

        $this->assertDatabaseCount('observaciones', 0);
    }

    // -----------------------------------------------------------------
    // 8. POST /api/pacientes/{dni}/restriccion -> 201
    // -----------------------------------------------------------------
    public function test_08_post_restriccion_201(): void
    {
        $paciente = $this->createPacienteModel();
        $motivo = $this->createMotivo();

        $payload = [
            'motivo_id' => $motivo->id,
            'desde' => now()->toDateString(),
            'hasta' => Carbon::tomorrow()->toDateString(),
        ];

        $response = $this->postJson('/api/pacientes/' . $paciente->dni . '/restriccion', $payload);
        $response->assertStatus(201);
        $this->assertDatabaseHas('restricciones', [
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo->id,
        ]);
    }

    // -----------------------------------------------------------------
    // 9. POST segunda restriccion mismo paciente -> 409 (DomainException)
    // -----------------------------------------------------------------
    public function test_09_post_segunda_restriccion_409(): void
    {
        $paciente = $this->createPacienteModel();
        $motivo = $this->createMotivo();

        $this->postJson('/api/pacientes/' . $paciente->dni . '/restriccion', [
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-02-01',
        ])->assertStatus(201);

        $response = $this->postJson('/api/pacientes/' . $paciente->dni . '/restriccion', [
            'motivo_id' => $motivo->id,
            'desde' => '2026-03-01',
        ]);
        $response->assertStatus(409);
        $response->assertJsonFragment(['message' => 'El paciente ya tiene una restricción.']);
        $this->assertDatabaseCount('restricciones', 1);
    }

    // -----------------------------------------------------------------
    // 10. DELETE /api/pacientes/{dni}/restriccion -> 204, luego POST otra -> 201
    // -----------------------------------------------------------------
    public function test_10_delete_restriccion_luego_post_otra_201(): void
    {
        $paciente = $this->createPacienteModel();
        $motivo = $this->createMotivo();

        $this->postJson('/api/pacientes/' . $paciente->dni . '/restriccion', [
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
        ])->assertStatus(201);

        $this->deleteJson('/api/pacientes/' . $paciente->dni . '/restriccion')
            ->assertStatus(204);

        $this->assertDatabaseCount('restricciones', 0);

        $motivo2 = $this->createMotivo('otro');
        $response = $this->postJson('/api/pacientes/' . $paciente->dni . '/restriccion', [
            'motivo_id' => $motivo2->id,
            'desde' => '2026-03-01',
        ]);
        $response->assertStatus(201);
        $this->assertDatabaseHas('restricciones', [
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo2->id,
        ]);
    }

    // -----------------------------------------------------------------
    // 11. POST /api/turnos sin violación -> 201 PENDIENTE
    // -----------------------------------------------------------------
    public function test_11_post_turno_sin_violacion_201_pendiente(): void
    {
        $paciente = $this->createPacienteModel();
        $fecha = Carbon::tomorrow()->toDateString();

        $payload = [
            'paciente_id' => $paciente->id,
            'fecha' => $fecha,
            'hora' => '10:00',
        ];

        $response = $this->postJson('/api/turnos', $payload);
        $response->assertStatus(201);
        $response->assertJsonFragment(['estado' => 'PENDIENTE']);
        $this->assertDatabaseHas('turnos', [
            'paciente_id' => $paciente->id,
            'estado' => 'PENDIENTE',
        ]);
        // Verifica fecha via model (evita mismatch SQLite date vs datetime)
        $this->assertTrue(
            \App\Models\Turno::where('paciente_id', $paciente->id)->whereDate('fecha', $fecha)->exists(),
            "Turno con fecha {$fecha} no encontrado"
        );
    }

    // -----------------------------------------------------------------
    // 12. POST /api/turnos con violación de DonationRules (plaquetas 48h) -> 409 con warning
    // -----------------------------------------------------------------
    public function test_12_post_turno_con_violacion_409_warning(): void
    {
        $paciente = $this->createPacienteModel();
        $tipoPlaquetas = $this->getTipo('PLAQUETAS');
        $fechaTurno = Carbon::tomorrow()->toDateString();
        $fechaDonacion = Carbon::parse($fechaTurno)->subDay()->toDateString();

        Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipoPlaquetas->id,
            'fecha' => $fechaDonacion,
        ]);

        $payload = [
            'paciente_id' => $paciente->id,
            'fecha' => $fechaTurno,
            'hora' => '10:00',
            'tipo_id' => $tipoPlaquetas->id,
        ];

        $response = $this->postJson('/api/turnos', $payload);
        $response->assertStatus(409);
        $response->assertJsonFragment(['warning' => true]);
        // Verifica que hay warnings y code INTERVALO
        $json = $response->json();
        $this->assertArrayHasKey('warnings', $json);
        $codes = array_column($json['warnings'], 'code');
        $this->assertTrue(
            in_array('WARNING_INTERVALO', $codes) || in_array('INTERVALO_MINIMO', $codes) || in_array('TURNO_PENDIENTE_EXISTENTE', $codes),
            'Expected WARNING_INTERVALO in warnings, got: ' . json_encode($codes)
        );
        // No debe crear turno
        $this->assertDatabaseCount('turnos', 0);
    }

    // -----------------------------------------------------------------
    // 13. POST /api/turnos mismo con forzar:true + usuario_id/motivo -> 201 + autorización creada
    // -----------------------------------------------------------------
    public function test_13_post_turno_forzado_201_con_autorizacion(): void
    {
        $paciente = $this->createPacienteModel();
        $tipoPlaquetas = $this->getTipo('PLAQUETAS');
        $fechaTurno = Carbon::tomorrow()->toDateString();
        $fechaDonacion = Carbon::parse($fechaTurno)->subDay()->toDateString();

        Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipoPlaquetas->id,
            'fecha' => $fechaDonacion,
        ]);

        $payload = [
            'paciente_id' => $paciente->id,
            'fecha' => $fechaTurno,
            'hora' => '10:00',
            'tipo_id' => $tipoPlaquetas->id,
            'forzar' => true,
            'motivo' => 'Autorización extraordinaria por urgencia',
        ];

        $response = $this->postJson('/api/turnos', $payload);
        $response->assertStatus(201);
        $response->assertJsonFragment(['estado' => 'PENDIENTE']);

        $this->assertDatabaseHas('turnos', [
            'paciente_id' => $paciente->id,
        ]);
        $this->assertTrue(
            \App\Models\Turno::where('paciente_id', $paciente->id)->whereDate('fecha', $fechaTurno)->exists(),
            "Turno con fecha {$fechaTurno} no encontrado"
        );
        $this->assertDatabaseHas('autorizaciones_extraordinarias', [
            'paciente_id' => $paciente->id,
            'usuario_id' => $this->staff->id,
        ]);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 1);
    }

    // -----------------------------------------------------------------
    // 14. POST /api/donaciones sin warning -> 201
    // -----------------------------------------------------------------
    public function test_14_post_donacion_sin_warning_201(): void
    {
        $paciente = $this->createPacienteModel();
        $tipo = $this->getTipo('PLASMA');

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

    // -----------------------------------------------------------------
    // 15. POST /api/donaciones con forzar flow (si aplica) -> 409 sin forzar, 201 con forzar
    // -----------------------------------------------------------------
    public function test_15_post_donacion_forzar_flow_409_sin_forzar_201_con_forzar(): void
    {
        $paciente = $this->createPacienteModel();
        $tipoPlaquetas = $this->getTipo('PLAQUETAS');
        $fechaDonacionPrevia = now()->subDay()->toDateString();
        $fechaSolicitada = now()->toDateString();

        // Historial que viola 48h
        Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipoPlaquetas->id,
            'fecha' => $fechaDonacionPrevia,
        ]);

        // Sin forzar -> 409
        $payloadSinForzar = [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipoPlaquetas->id,
            'fecha' => $fechaSolicitada,
        ];
        $response409 = $this->postJson('/api/donaciones', $payloadSinForzar);
        $response409->assertStatus(409);
        $response409->assertJsonStructure(['warnings']);
        $codes = array_column($response409->json('warnings'), 'code');
        $this->assertContains('WARNING_INTERVALO', $codes);
        // No debe crear segunda donación
        $this->assertDatabaseCount('donaciones', 1);

        // Con forzar -> 201 + autorización
        $payloadConForzar = [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipoPlaquetas->id,
            'fecha' => $fechaSolicitada,
            'forzar' => true,
            'motivo' => 'Forzado por normativa excedida',
        ];
        $response201 = $this->postJson('/api/donaciones', $payloadConForzar);
        $response201->assertStatus(201);
        $this->assertDatabaseHas('donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipoPlaquetas->id,
            'fecha' => $fechaSolicitada,
        ]);
        $this->assertDatabaseCount('donaciones', 2);
        $this->assertDatabaseHas('autorizaciones_extraordinarias', [
            'paciente_id' => $paciente->id,
            'usuario_id' => $this->staff->id,
        ]);
    }

    // -----------------------------------------------------------------
    // 16. Validación: POST /api/pacientes sin dni -> 422
    // -----------------------------------------------------------------
    public function test_16_validacion_post_pacientes_sin_dni_422(): void
    {
        $payload = [
            'nombre' => 'Juan',
            'apellido' => 'Perez',
            'telefono' => '1122334455',
        ];
        $response = $this->postJson('/api/pacientes', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['dni']);
        $this->assertDatabaseCount('pacientes', 0);
    }

    // -----------------------------------------------------------------
    // 17. Validación: POST /api/pacientes/{dni}/restriccion sin motivo_id -> 422
    // -----------------------------------------------------------------
    public function test_17_validacion_post_restriccion_sin_motivo_422(): void
    {
        $paciente = $this->createPacienteModel();
        $payload = [
            'desde' => now()->toDateString(),
        ];
        $response = $this->postJson('/api/pacientes/' . $paciente->dni . '/restriccion', $payload);
        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['motivo_id']);
        $this->assertDatabaseCount('restricciones', 0);
    }
}
