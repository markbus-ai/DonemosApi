<?php

namespace Tests\Unit\Services;

use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Services\RestriccionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RestriccionServiceTest extends TestCase
{
    use RefreshDatabase;

    private RestriccionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RestriccionService();
    }

    private function seedAptitudes(): void
    {
        foreach (['APTO', 'APTO_OBSERVACION', 'NO_APTO'] as $tipo) {
            Aptitud::firstOrCreate(['tipo' => $tipo]);
        }
    }

    private function createPaciente(): Paciente
    {
        $aptitud = Aptitud::where('tipo', 'APTO')->firstOrFail();

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

    public function test_crea_restriccion(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $restriccion = $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-02-01',
        ]);

        $this->assertNotNull($restriccion->id);
        $this->assertEquals($paciente->id, $restriccion->paciente_id);
        $this->assertEquals($motivo->id, $restriccion->motivo_id);
        $this->assertEquals('2026-01-01', $restriccion->desde);
        $this->assertEquals('2026-02-01', $restriccion->hasta);
        $this->assertDatabaseCount('restricciones', 1);
    }

    public function test_no_permite_una_segunda_restriccion(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-02-01',
        ]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El paciente ya tiene una restricción.');

        $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-03-01',
            'hasta' => '2026-04-01',
        ]);
    }

    public function test_permite_hasta_null(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $restriccion = $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-03-01',
            'hasta' => null,
        ]);

        $this->assertNull($restriccion->hasta);
        $this->assertDatabaseHas('restricciones', [
            'id' => $restriccion->id,
            'hasta' => null,
        ]);
    }

    public function test_elimina_restriccion(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $restriccion = $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-02-01',
        ]);

        $this->assertDatabaseCount('restricciones', 1);

        $this->service->delete($restriccion);

        $this->assertDatabaseCount('restricciones', 0);
        $this->assertEquals(0, $paciente->restriccion()->count());
    }
}
