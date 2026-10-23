<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Turno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * Byte-for-byte snapshots of the 409 bodies the desktop and mobile clients
 * parse. Any refactor of how conflicts are rendered must keep them identical.
 */
class ConflictEnvelopeSnapshotTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 13:00:00');
        Queue::fake();
        config(['agenda_capacidad.minutos_por_dia' => 480, 'agenda_capacidad.duracion_minima' => 15]);
        $this->sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $this->sede->update(['codigo_localidad' => '1']);

        $this->actingAsStaff($this->sede);
    }

    private function paciente(): Paciente
    {
        return Paciente::factory()->create(['telefono' => '2254000000']);
    }

    public function test_turno_rule_warning_envelope(): void
    {
        $paciente = $this->paciente();
        Turno::create(['paciente_id' => $paciente->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-11-01', 'hora' => '08:00', 'estado' => 'PENDIENTE']);

        $response = $this->postJson('/api/turnos', ['paciente_id' => $paciente->id, 'fecha' => '2026-11-02', 'hora' => '09:00']);

        $response->assertStatus(409);
        $this->assertSame(
            '{"warning":true,"code":"TURNO_PENDIENTE_EXISTENTE","message":"Paciente ya tiene turno pendiente","warnings":[{"code":"TURNO_PENDIENTE_EXISTENTE","message":"Paciente ya tiene turno pendiente"}]}',
            $response->getContent(),
        );
    }

    public function test_turno_taken_slot_envelope(): void
    {
        Turno::create(['paciente_id' => $this->paciente()->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-11-02', 'hora' => '09:00', 'estado' => 'PENDIENTE']);

        $response = $this->postJson('/api/turnos', ['paciente_id' => $this->paciente()->id, 'fecha' => '2026-11-02', 'hora' => '09:00']);

        $response->assertStatus(409);
        $this->assertSame(
            '{"warning":true,"code":"HORARIO_OCUPADO","message":"Ese horario ya est\u00e1 ocupado. Eleg\u00ed otro horario.","warnings":[{"code":"HORARIO_OCUPADO","message":"Ese horario ya est\u00e1 ocupado. Eleg\u00ed otro horario."}]}',
            $response->getContent(),
        );
    }

    public function test_turno_capacity_envelope(): void
    {
        config(['agenda_capacidad.minutos_por_dia' => 15]);
        Turno::create(['paciente_id' => $this->paciente()->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-11-02', 'hora' => '08:00', 'estado' => 'PENDIENTE']);

        $response = $this->postJson('/api/turnos', ['paciente_id' => $this->paciente()->id, 'fecha' => '2026-11-02', 'hora' => '09:00']);

        $response->assertStatus(409);
        $this->assertSame(
            '{"warning":true,"code":"CAPACIDAD_EXCEDIDA","message":"CAPACIDAD_EXCEDIDA: la sede alcanz\u00f3 el l\u00edmite diario (15 min); turno de 15 min, 0 min disponibles.","warnings":[{"code":"CAPACIDAD_EXCEDIDA","message":"CAPACIDAD_EXCEDIDA: la sede alcanz\u00f3 el l\u00edmite diario (15 min); turno de 15 min, 0 min disponibles."}]}',
            $response->getContent(),
        );
    }

    public function test_donacion_warning_envelope(): void
    {
        $tipo = TipoDonacion::firstOrCreate(['codigo' => 'SANGRE'], ['nombre' => 'SANGRE']);

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'hemoglobina' => 11,
        ]);

        $response->assertStatus(409);
        $this->assertSame(
            '{"warnings":[{"code":"HEMOGLOBINA_BAJA","message":"hemoglobina (11) por debajo del m\u00ednimo: 12.5 g\/dL."}]}',
            $response->getContent(),
        );
    }
}
