<?php

namespace Tests\Unit\Services;

use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Services\ObservacionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObservacionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ObservacionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ObservacionService();
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

    public function test_crea_observacion(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $observacion = $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-02-01',
        ]);

        $this->assertNotNull($observacion->id);
        $this->assertEquals($paciente->id, $observacion->paciente_id);
        $this->assertEquals($motivo->id, $observacion->motivo_id);
        $this->assertEquals('2026-01-01', $observacion->desde);
        $this->assertEquals('2026-02-01', $observacion->hasta);
        $this->assertDatabaseCount('observaciones', 1);
    }

    public function test_permite_multiples_observaciones(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-01-15',
        ]);

        $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-02-01',
            'hasta' => '2026-02-15',
        ]);

        $this->assertDatabaseCount('observaciones', 2);
        $this->assertEquals(2, $paciente->observaciones()->count());
    }

    public function test_permite_hasta_null(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $observacion = $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-03-01',
            'hasta' => null,
        ]);

        $this->assertNull($observacion->hasta);
        $this->assertDatabaseHas('observaciones', [
            'id' => $observacion->id,
            'hasta' => null,
        ]);
    }

    public function test_elimina_observacion(): void
    {
        $this->seedAptitudes();
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $observacion = $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-02-01',
        ]);

        $this->assertDatabaseCount('observaciones', 1);

        $this->service->delete($observacion);

        $this->assertDatabaseCount('observaciones', 0);
        $this->assertEquals(0, $paciente->observaciones()->count());
    }
}
