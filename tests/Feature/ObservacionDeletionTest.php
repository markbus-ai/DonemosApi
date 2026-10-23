<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Observacion;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Removing observations: by id (flat and nested under the patient) and the
 * legacy per-patient route, which must pick a deterministic row.
 */
class ObservacionDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Motivo $motivo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 13:00:00');
        $this->motivo = Motivo::firstOrCreate(['nombre' => 'Prueba']);

        Sanctum::actingAs(Usuario::create([
            'username' => 'staff_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => Rol::firstOrCreate(['nombre' => 'staff'])->id,
        ]), ['*'], 'staff');
    }

    private function paciente(): Paciente
    {
        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '2254000000',
            'aptitud_id' => Aptitud::firstOrCreate(['tipo' => 'APTO_OBSERVACION'])->id,
        ]);
    }

    private function observacion(Paciente $paciente, string $desde, ?string $hasta = null): Observacion
    {
        return $paciente->observaciones()->create(['motivo_id' => $this->motivo->id, 'desde' => $desde, 'hasta' => $hasta]);
    }

    public function test_legacy_route_removes_the_latest_active_observation(): void
    {
        $paciente = $this->paciente();
        $vencida = $this->observacion($paciente, '2026-01-01', '2026-02-01');
        $reciente = $this->observacion($paciente, '2026-09-01');
        $anterior = $this->observacion($paciente, '2026-03-01');

        $this->deleteJson("/api/pacientes/{$paciente->dni}/observacion")->assertNoContent();

        $this->assertModelMissing($reciente);
        $this->assertModelExists($anterior);
        $this->assertModelExists($vencida);
    }

    public function test_legacy_route_falls_back_to_the_latest_observation_when_none_is_active(): void
    {
        $paciente = $this->paciente();
        $vieja = $this->observacion($paciente, '2025-01-01', '2025-02-01');
        $nueva = $this->observacion($paciente, '2026-01-01', '2026-02-01');

        $this->deleteJson("/api/pacientes/{$paciente->dni}/observacion")->assertNoContent();

        $this->assertModelMissing($nueva);
        $this->assertModelExists($vieja);
    }

    public function test_legacy_route_is_404_without_observations(): void
    {
        $paciente = $this->paciente();

        $this->deleteJson("/api/pacientes/{$paciente->dni}/observacion")
            ->assertNotFound()
            ->assertExactJson(['message' => 'No se encontró el recurso solicitado.']);
    }

    public function test_two_observations_are_removed_one_by_one_by_id(): void
    {
        $paciente = $this->paciente();
        $primera = $this->observacion($paciente, '2026-03-01');
        $segunda = $this->observacion($paciente, '2026-09-01');

        $this->deleteJson("/api/observaciones/{$primera->id}")->assertNoContent();
        $this->assertModelMissing($primera);
        $this->assertModelExists($segunda);

        $this->deleteJson("/api/pacientes/{$paciente->dni}/observaciones/{$segunda->id}")->assertNoContent();
        $this->assertModelMissing($segunda);
        $this->assertSame(0, Observacion::count());
    }

    public function test_nested_route_is_404_for_another_patients_observation(): void
    {
        $duenio = $this->paciente();
        $otro = $this->paciente();
        $observacion = $this->observacion($duenio, '2026-09-01');

        $this->deleteJson("/api/pacientes/{$otro->dni}/observaciones/{$observacion->id}")
            ->assertNotFound()
            ->assertExactJson(['message' => 'No se encontró el recurso solicitado.']);

        $this->assertModelExists($observacion);
    }

    public function test_unknown_observation_id_is_404(): void
    {
        $this->deleteJson('/api/observaciones/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => 'No se encontró el recurso solicitado.']);
    }
}
