<?php

namespace Tests\Unit\Services;

use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\Restriccion;
use App\Services\RestriccionService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Deferral history is append-only: many rows per patient, prior active rows
 * are closed on a new insert, and close() never deletes.
 */
class RestriccionServiceTest extends TestCase
{
    use RefreshDatabase;

    private RestriccionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RestriccionService();
    }

    private function createPaciente(): Paciente
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

    private function createMotivo(array $overrides = []): Motivo
    {
        return Motivo::create(array_merge([
            'nombre' => fake()->unique()->word(),
        ], $overrides));
    }

    public function test_creates_active_permanent_restriccion(): void
    {
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $restriccion = $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => null,
        ]);

        $this->assertNotNull($restriccion->id);
        $this->assertSame($paciente->id, $restriccion->paciente_id);
        $this->assertNull($restriccion->hasta);
        $this->assertTrue($restriccion->permanente);
        $this->assertSame(1, $paciente->restricciones()->count());
    }

    public function test_create_with_hasta_is_not_permanent(): void
    {
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $restriccion = $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-04-01',
        ]);

        $this->assertFalse($restriccion->permanente);
        $this->assertSame('2026-04-01', $restriccion->hasta);
    }

    public function test_second_create_closes_prior_active_row_and_keeps_history(): void
    {
        $paciente = $this->createPaciente();
        $motivo1 = $this->createMotivo();
        $motivo2 = $this->createMotivo();

        $first = $this->service->create($paciente, [
            'motivo_id' => $motivo1->id,
            'desde' => '2026-01-01',
            'hasta' => null,
        ]);

        $second = $this->service->create($paciente, [
            'motivo_id' => $motivo2->id,
            'desde' => '2026-06-01',
            'hasta' => null,
        ]);

        $this->assertSame(2, Restriccion::where('paciente_id', $paciente->id)->count());

        $first->refresh();
        $this->assertFalse($first->permanente);
        $this->assertSame('2026-06-01', $first->hasta);

        $this->assertNull($second->fresh()->hasta);
        $this->assertTrue($second->fresh()->permanente);
        $this->assertSame($second->id, $paciente->fresh()->activeDeferral->id);
    }

    public function test_closed_rows_are_retained_and_only_one_is_active(): void
    {
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $this->service->create($paciente, ['motivo_id' => $motivo->id, 'desde' => '2026-01-01', 'hasta' => '2026-02-01']);
        $this->service->create($paciente, ['motivo_id' => $motivo->id, 'desde' => '2026-03-01', 'hasta' => '2026-04-01']);
        $active = $this->service->create($paciente, ['motivo_id' => $motivo->id, 'desde' => '2026-05-01', 'hasta' => null]);

        $this->assertSame(3, $paciente->restricciones()->count());
        $this->assertSame(3, Restriccion::where('paciente_id', $paciente->id)->count());
        $this->assertSame($active->id, $paciente->fresh()->activeDeferral->id);
    }

    public function test_close_marks_row_closed_without_deleting(): void
    {
        $paciente = $this->createPaciente();
        $motivo = $this->createMotivo();

        $restriccion = $this->service->create($paciente, [
            'motivo_id' => $motivo->id,
            'desde' => '2026-01-01',
            'hasta' => null,
        ]);

        $this->service->close($restriccion);

        $this->assertSame(1, Restriccion::where('paciente_id', $paciente->id)->count());

        $restriccion->refresh();
        $this->assertFalse($restriccion->permanente);
        $this->assertSame(now()->toDateString(), $restriccion->hasta);
        $this->assertNull($paciente->fresh()->activeDeferral);
    }

    public function test_duplicate_motivo_codigo_is_rejected(): void
    {
        $this->createMotivo(['nombre' => 'primer_tatuaje', 'codigo' => 'TATUAJE']);

        $this->expectException(QueryException::class);

        $this->createMotivo(['nombre' => 'segundo_tatuaje', 'codigo' => 'TATUAJE']);
    }
}
