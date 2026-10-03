<?php

namespace Tests\Unit\Services;

use App\Http\Requests\Aptitud\UpdateAptitudRequest;
use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\Restriccion;
use App\Models\Rol;
use App\Models\Usuario;
use App\Services\AptitudService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AptitudServiceTest extends TestCase
{
    use RefreshDatabase;

    private AptitudService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AptitudService();
    }

    private function seedAptitudes(): void
    {
        foreach (['APTO', 'APTO_OBSERVACION', 'NO_APTO'] as $tipo) {
            Aptitud::firstOrCreate(['tipo' => $tipo]);
        }
    }

    private function createPaciente(string $tipoInicial = 'APTO'): Paciente
    {
        $aptitud = Aptitud::where('tipo', $tipoInicial)->firstOrFail();

        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ]);
    }

    private function createMotivo(): Motivo
    {
        return Motivo::create([
            'nombre' => fake()->unique()->word(),
        ]);
    }

    private function createActiveDeferral(Paciente $paciente, Motivo $motivo, array $overrides = []): Restriccion
    {
        return $paciente->restricciones()->create(array_merge([
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => null,
            'permanente' => true,
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // Deferral history on return to APTO / APTO_OBSERVACION
    // -----------------------------------------------------------------

    public function test_apto_closes_active_deferral_without_deleting(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('NO_APTO');
        $motivo = $this->createMotivo();
        $deferral = $this->createActiveDeferral($paciente, $motivo);

        $updated = $this->service->update($paciente, ['tipo' => 'APTO']);

        $this->assertSame('APTO', $updated->aptitud->tipo);
        $this->assertSame(1, Restriccion::where('paciente_id', $paciente->id)->count());

        $deferral->refresh();
        $this->assertFalse($deferral->permanente);
        $this->assertSame(now()->toDateString(), $deferral->hasta);
        $this->assertNull($updated->fresh()->activeDeferral);
    }

    public function test_apto_observacion_also_closes_active_deferral_without_deleting(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('NO_APTO');
        $motivo = $this->createMotivo();
        $deferral = $this->createActiveDeferral($paciente, $motivo);

        $updated = $this->service->update($paciente, [
            'tipo' => 'APTO_OBSERVACION',
            'motivo_id' => $motivo->id,
            'desde' => '2026-04-01',
            'hasta' => null,
        ]);

        $this->assertSame('APTO_OBSERVACION', $updated->aptitud->tipo);
        $this->assertSame(1, Restriccion::where('paciente_id', $paciente->id)->count());

        $deferral->refresh();
        $this->assertFalse($deferral->permanente);
        $this->assertSame(now()->toDateString(), $deferral->hasta);

        $this->assertSame(1, $updated->fresh()->observaciones()->count());
    }

    public function test_apto_does_not_delete_closed_history(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('NO_APTO');
        $motivo = $this->createMotivo();

        $this->createActiveDeferral($paciente, $motivo, ['desde' => '2025-01-01', 'hasta' => '2025-02-01', 'permanente' => false]);
        $this->createActiveDeferral($paciente, $motivo, ['desde' => '2025-03-01', 'hasta' => '2025-04-01', 'permanente' => false]);
        $this->createActiveDeferral($paciente, $motivo);

        $updated = $this->service->update($paciente, ['tipo' => 'APTO']);

        $this->assertSame(3, Restriccion::where('paciente_id', $paciente->id)->count());
        $this->assertNull($updated->fresh()->activeDeferral);
    }

    // -----------------------------------------------------------------
    // NO_APTO transitions preserve history and keep a single active row
    // -----------------------------------------------------------------

    public function test_no_apto_closes_prior_active_and_inserts_new_row(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('NO_APTO');
        $motivo1 = $this->createMotivo();
        $motivo2 = $this->createMotivo();
        $prior = $this->createActiveDeferral($paciente, $motivo1);

        $updated = $this->service->update($paciente, [
            'tipo' => 'NO_APTO',
            'motivo_id' => $motivo2->id,
            'desde' => '2026-06-01',
            'hasta' => null,
        ]);

        $this->assertSame('NO_APTO', $updated->aptitud->tipo);
        $this->assertSame(2, Restriccion::where('paciente_id', $paciente->id)->count());

        $prior->refresh();
        $this->assertFalse($prior->permanente);
        $this->assertSame('2026-06-01', $prior->hasta);

        $active = $updated->fresh()->activeDeferral;
        $this->assertNotNull($active);
        $this->assertSame($motivo2->id, $active->motivo_id);
        $this->assertTrue($active->permanente);
        $this->assertNull($active->hasta);
    }

    public function test_no_apto_with_hasta_creates_temporary_row(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO');
        $motivo = $this->createMotivo();

        $updated = $this->service->update($paciente, [
            'tipo' => 'NO_APTO',
            'motivo_id' => $motivo->id,
            'desde' => '2026-06-01',
            'hasta' => '2026-12-01',
        ]);

        $active = $updated->fresh()->restricciones()->latest('desde')->first();
        $this->assertFalse($active->permanente);
        $this->assertSame('2026-12-01', $active->hasta);
    }

    public function test_no_apto_removes_observaciones_but_keeps_deferral_rows(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO_OBSERVACION');
        $motivo = $this->createMotivo();

        $paciente->observaciones()->create(['motivo_id' => $motivo->id, 'desde' => '2026-01-01', 'hasta' => null]);
        $paciente->observaciones()->create(['motivo_id' => $motivo->id, 'desde' => '2026-02-01', 'hasta' => null]);

        $updated = $this->service->update($paciente, [
            'tipo' => 'NO_APTO',
            'motivo_id' => $motivo->id,
            'desde' => '2026-06-01',
            'hasta' => '2026-12-01',
        ]);

        $this->assertSame(0, $updated->fresh()->observaciones()->count());
        $this->assertSame(1, Restriccion::where('paciente_id', $paciente->id)->count());
    }

    // -----------------------------------------------------------------
    // APTO_OBSERVACION observacion behaviour (unchanged)
    // -----------------------------------------------------------------

    public function test_apto_observacion_con_datos_crea_observacion(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO');
        $motivo = $this->createMotivo();

        $updated = $this->service->update($paciente, [
            'tipo' => 'APTO_OBSERVACION',
            'motivo_id' => $motivo->id,
            'desde' => '2026-02-01',
            'hasta' => '2026-03-01',
        ]);

        $this->assertSame('APTO_OBSERVACION', $updated->aptitud->tipo);
        $this->assertSame(1, $updated->observaciones()->count());
        $this->assertDatabaseHas('observaciones', [
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo->id,
            'desde' => '2026-02-01',
            'hasta' => '2026-03-01',
        ]);
    }

    public function test_apto_observacion_permite_hasta_null(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO');
        $motivo = $this->createMotivo();

        $updated = $this->service->update($paciente, [
            'tipo' => 'APTO_OBSERVACION',
            'motivo_id' => $motivo->id,
            'desde' => '2026-05-01',
            'hasta' => null,
        ]);

        $this->assertSame('APTO_OBSERVACION', $updated->aptitud->tipo);
        $this->assertNull($updated->observaciones()->first()->hasta);
    }

    // -----------------------------------------------------------------
    // Validación (unchanged behaviour)
    // -----------------------------------------------------------------

    public function test_apto_observacion_sin_motivo_falla_validacion(): void
    {
        $request = new UpdateAptitudRequest();
        $request->merge([
            'tipo' => 'APTO_OBSERVACION',
            'desde' => '2026-01-01',
        ]);

        $validator = Validator::make($request->all(), $request->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('motivo_id', $validator->errors()->toArray());
    }

    public function test_no_apto_sin_desde_falla_validacion(): void
    {
        $this->seedAptitudes();
        $m = $this->createMotivo();
        $data = [
            'tipo' => 'NO_APTO',
            'motivo_id' => $m->id,
        ];

        $req = new UpdateAptitudRequest();
        $req->merge($data);
        $validator = Validator::make($req->all(), $req->rules());

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('desde', $validator->errors()->toArray());
    }

    public function test_apto_sin_motivo_pasa_validacion(): void
    {
        $request = new UpdateAptitudRequest();
        $request->merge(['tipo' => 'APTO']);
        $validator = Validator::make($request->all(), $request->rules());
        $this->assertFalse($validator->fails());
    }

    public function test_hasta_debe_ser_after_or_equal_desde(): void
    {
        $this->seedAptitudes();
        $motivo = $this->createMotivo();

        $request = new UpdateAptitudRequest();
        $request->merge([
            'tipo' => 'APTO_OBSERVACION',
            'motivo_id' => $motivo->id,
            'desde' => '2026-03-10',
            'hasta' => '2026-03-01',
        ]);
        $validator = Validator::make($request->all(), $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('hasta', $validator->errors()->toArray());
    }

    public function test_patch_aptitud_apto_observacion_sin_motivo_retorna_422(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO');

        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);
        $staff = Usuario::create([
            'username' => fake()->unique()->userName(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);
        Sanctum::actingAs($staff, ['*'], 'staff');

        $response = $this->patchJson('/api/pacientes/'.$paciente->dni.'/aptitud', [
            'tipo' => 'APTO_OBSERVACION',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['motivo_id', 'desde']);
    }

    // -----------------------------------------------------------------
    // Transacción: si falla creación, aptitud no cambia (rollback)
    // -----------------------------------------------------------------

    public function test_transaccion_rollback_si_falla_observacion_aptitud_no_cambia(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO');
        $aptitudOriginalId = $paciente->aptitud_id;

        try {
            $this->service->update($paciente, [
                'tipo' => 'APTO_OBSERVACION',
                'motivo_id' => 999999,
                'desde' => '2026-01-01',
                'hasta' => null,
            ]);
            $this->fail('Se esperaba excepción por FK inválida');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        $paciente->refresh();
        $this->assertSame($aptitudOriginalId, $paciente->aptitud_id);
        $this->assertSame('APTO', $paciente->aptitud->tipo);
        $this->assertSame(0, $paciente->observaciones()->count());
    }

    public function test_transaccion_rollback_si_falla_restriccion_aptitud_no_cambia(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO');
        $motivo = $this->createMotivo();
        $paciente->observaciones()->create([
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => null,
        ]);
        $aptitudOriginalId = $paciente->aptitud_id;
        $obsCountBefore = $paciente->observaciones()->count();

        try {
            $this->service->update($paciente, [
                'tipo' => 'NO_APTO',
                'motivo_id' => 888888,
                'desde' => '2026-02-01',
            ]);
            $this->fail('Se esperaba excepción por FK inválida');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        $paciente->refresh();
        $this->assertSame($aptitudOriginalId, $paciente->aptitud_id);
        $this->assertSame($obsCountBefore, $paciente->observaciones()->count());
        $this->assertSame(0, Restriccion::where('paciente_id', $paciente->id)->count());
    }

    public function test_aptitud_inexistente_lanza_excepcion(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO');

        $this->expectException(ModelNotFoundException::class);

        $this->service->update($paciente, ['tipo' => 'INEXISTENTE']);
    }
}
