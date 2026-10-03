<?php

namespace Tests\Unit\Services;

use App\Http\Requests\Aptitud\UpdateAptitudRequest;
use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Services\AptitudService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
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

    // -----------------------------------------------------------------
    // Invariante: APTO -> 0 observaciones, 0 restricciones
    // -----------------------------------------------------------------
    public function test_cambia_a_apto_desde_no_apto_elimina_restriccion(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('NO_APTO');
        $motivo = $this->createMotivo();

        // Precondición: paciente NO_APTO con 1 restricción (invariante)
        $paciente->restriccion()->create([
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => null,
        ]);
        $this->assertEquals(1, $paciente->restriccion()->count());
        $this->assertEquals(0, $paciente->observaciones()->count());

        $updated = $this->service->update($paciente, ['tipo' => 'APTO']);

        $updated->load('aptitud');
        $this->assertEquals('APTO', $updated->aptitud->tipo);
        // Invariante APTO
        $this->assertEquals(0, $updated->observaciones()->count());
        $this->assertEquals(0, $updated->fresh()->observaciones()->count());
        $this->assertNull($updated->fresh()->restriccion);
        $this->assertDatabaseCount('restricciones', 0);
        $this->assertDatabaseCount('observaciones', 0);
    }

    public function test_cambia_a_apto_elimina_observaciones_y_restriccion(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO_OBSERVACION');
        $motivo = $this->createMotivo();

        $paciente->observaciones()->create([
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-10',
            'hasta' => '2026-02-10',
        ]);
        $paciente->observaciones()->create([
            'motivo_id' => $motivo->id,
            'desde' => '2026-03-01',
            'hasta' => null,
        ]);
        $this->assertEquals(2, $paciente->observaciones()->count());

        $updated = $this->service->update($paciente, ['tipo' => 'APTO']);

        $this->assertEquals('APTO', $updated->aptitud->tipo);
        $this->assertEquals(0, $updated->fresh()->observaciones()->count());
        $this->assertNull($updated->fresh()->restriccion);
    }

    // -----------------------------------------------------------------
    // Invariante: APTO_OBSERVACION -> >=1 observación, 0 restricciones
    // -----------------------------------------------------------------
    public function test_cambia_a_apto_observacion_con_datos_crea_observacion(): void
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

        $updated->load('aptitud');
        $this->assertEquals('APTO_OBSERVACION', $updated->aptitud->tipo);
        $this->assertEquals(1, $updated->observaciones()->count());
        $this->assertEquals(0, $updated->restriccion()->count());
        $this->assertDatabaseCount('observaciones', 1);
        $this->assertDatabaseCount('restricciones', 0);
        $this->assertDatabaseHas('observaciones', [
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo->id,
            'desde' => '2026-02-01',
            'hasta' => '2026-03-01',
        ]);
    }

    public function test_cambia_a_apto_observacion_elimina_restriccion_previa(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('NO_APTO');
        $motivo = $this->createMotivo();

        $paciente->restriccion()->create([
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
        ]);
        $this->assertNotNull($paciente->fresh()->restriccion);

        $updated = $this->service->update($paciente, [
            'tipo' => 'APTO_OBSERVACION',
            'motivo_id' => $motivo->id,
            'desde' => '2026-04-01',
            'hasta' => null,
        ]);

        $this->assertEquals('APTO_OBSERVACION', $updated->aptitud->tipo);
        $this->assertNull($updated->fresh()->restriccion);
        $this->assertEquals(1, $updated->fresh()->observaciones()->count());
        $this->assertDatabaseCount('restricciones', 0);
    }

    public function test_cambia_a_apto_observacion_permite_hasta_null(): void
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

        $this->assertEquals('APTO_OBSERVACION', $updated->aptitud->tipo);
        $obs = $updated->observaciones()->first();
        $this->assertNull($obs->hasta);
    }

    // -----------------------------------------------------------------
    // Invariante: NO_APTO -> 0 observaciones, 1 restricción
    // -----------------------------------------------------------------
    public function test_cambia_a_no_apto_con_datos_crea_restriccion_elimina_observaciones(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO_OBSERVACION');
        $motivo = $this->createMotivo();

        // Precondición con 2 observaciones
        $paciente->observaciones()->create(['motivo_id' => $motivo->id, 'desde' => '2026-01-01', 'hasta' => null]);
        $paciente->observaciones()->create(['motivo_id' => $motivo->id, 'desde' => '2026-02-01', 'hasta' => null]);
        $this->assertEquals(2, $paciente->observaciones()->count());

        $updated = $this->service->update($paciente, [
            'tipo' => 'NO_APTO',
            'motivo_id' => $motivo->id,
            'desde' => '2026-06-01',
            'hasta' => '2026-12-01',
        ]);

        $updated->load('aptitud');
        $this->assertEquals('NO_APTO', $updated->aptitud->tipo);
        $this->assertEquals(0, $updated->fresh()->observaciones()->count());
        $this->assertNotNull($updated->fresh()->restriccion);
        $this->assertEquals(1, $updated->fresh()->restriccion()->count());
        $this->assertDatabaseCount('observaciones', 0);
        $this->assertDatabaseCount('restricciones', 1);
        $this->assertDatabaseHas('restricciones', [
            'paciente_id' => $paciente->id,
            'motivo_id' => $motivo->id,
            'desde' => '2026-06-01',
            'hasta' => '2026-12-01',
        ]);
    }

    public function test_cambia_a_no_apto_reemplaza_restriccion_existente(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('NO_APTO');
        $motivo1 = $this->createMotivo();
        $motivo2 = Motivo::create(['nombre' => fake()->unique()->word()]);

        $paciente->restriccion()->create([
            'motivo_id' => $motivo1->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-02-01',
        ]);
        $oldId = $paciente->fresh()->restriccion->id;

        $updated = $this->service->update($paciente, [
            'tipo' => 'NO_APTO',
            'motivo_id' => $motivo2->id,
            'desde' => '2026-03-01',
            'hasta' => null,
        ]);

        $this->assertEquals('NO_APTO', $updated->aptitud->tipo);
        $this->assertDatabaseCount('restricciones', 1);
        $new = $updated->fresh()->restriccion;
        $this->assertNotEquals($oldId, $new->id);
        $this->assertEquals($motivo2->id, $new->motivo_id);
    }

    // -----------------------------------------------------------------
    // Validación: APTO_OBSERVACION sin motivo_id debe fallar (422)
    // -----------------------------------------------------------------
    public function test_apto_observacion_sin_motivo_falla_validacion(): void
    {
        $request = new UpdateAptitudRequest();
        // Simular input tipo APTO_OBSERVACION sin motivo_id
        $request->merge([
            'tipo' => 'APTO_OBSERVACION',
            'desde' => '2026-01-01',
        ]);

        $rules = $request->rules();
        $validator = Validator::make($request->all(), $rules);

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('motivo_id', $validator->errors()->toArray());
    }

    public function test_no_apto_sin_desde_falla_validacion(): void
    {
        // Necesitamos un motivo existente para que exists pase; creamos uno temporal
        $this->seedAptitudes();
        $m = Motivo::create(['nombre' => 'tmp_motivo_val']);
        $data = [
            'tipo' => 'NO_APTO',
            'motivo_id' => $m->id,
            // falta desde
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
            'hasta' => '2026-03-01', // anterior a desde
        ]);
        $validator = Validator::make($request->all(), $request->rules());
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('hasta', $validator->errors()->toArray());
    }

    public function test_patch_aptitud_apto_observacion_sin_motivo_retorna_422(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO');

        $response = $this->patchJson('/api/pacientes/' . $paciente->dni . '/aptitud', [
            'tipo' => 'APTO_OBSERVACION',
            // sin motivo_id ni desde
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

        // Usar motivo_id inexistente para forzar violación de FK dentro de la transacción
        $invalidMotivoId = 999999;

        try {
            $this->service->update($paciente, [
                'tipo' => 'APTO_OBSERVACION',
                'motivo_id' => $invalidMotivoId,
                'desde' => '2026-01-01',
                'hasta' => null,
            ]);
            $this->fail('Se esperaba excepción por FK inválida');
        } catch (\Throwable $e) {
            // Esperado: QueryException por FK o similar
            $this->assertTrue(true);
        }

        $paciente->refresh();
        $this->assertEquals($aptitudOriginalId, $paciente->aptitud_id);
        $this->assertEquals('APTO', $paciente->aptitud->tipo);
        $this->assertEquals(0, $paciente->observaciones()->count());
        $this->assertDatabaseCount('observaciones', 0);
    }

    public function test_transaccion_rollback_si_falla_restriccion_aptitud_no_cambia(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO');
        // Precrear una observación para verificar que no se borra si hay rollback
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
                'motivo_id' => 888888, // inexistente
                'desde' => '2026-02-01',
            ]);
            $this->fail('Se esperaba excepción por FK inválida');
        } catch (\Throwable $e) {
            $this->assertTrue(true);
        }

        $paciente->refresh();
        $this->assertEquals($aptitudOriginalId, $paciente->aptitud_id);
        $this->assertEquals($obsCountBefore, $paciente->observaciones()->count());
        $this->assertDatabaseCount('restricciones', 0);
    }

    public function test_aptitud_inexistente_lanza_excepcion(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente('APTO');

        $this->expectException(ModelNotFoundException::class);

        $this->service->update($paciente, ['tipo' => 'INEXISTENTE']);
    }
}
