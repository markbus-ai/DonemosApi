<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Turno;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * HTTP contract for the turnos payload: list/store/update must embed the
 * paciente, sede and tipoDonacion relations so clients never do N+1 lookups,
 * while keeping the raw foreign keys for backward compatibility. A legacy
 * turno with a null tipo_id must still serialize with `tipo: null`.
 */
class TurnoResourceTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsStaff(): void
    {
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);

        $staff = Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);

        Sanctum::actingAs($staff, ['*'], 'staff');
    }

    private function paciente(array $overrides = []): Paciente
    {
        $aptitud = Aptitud::firstOrCreate(['tipo' => 'APTO']);

        return Paciente::create(array_merge([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ], $overrides));
    }

    private function sede(string $nombre = 'Sede Central'): Sede
    {
        return Sede::firstOrCreate(['nombre' => $nombre], ['activa' => true]);
    }

    private function tipo(string $codigo = 'SANGRE', int $duracion = 30): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(
            ['codigo' => $codigo],
            ['nombre' => $codigo, 'duracion_minutos' => $duracion],
        );
    }

    private function turno(array $overrides = []): Turno
    {
        return Turno::create(array_merge([
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $this->sede()->id,
            'tipo_id' => $this->tipo()->id,
            'fecha' => '2026-06-01',
            'hora' => '10:00',
            'estado' => 'PENDIENTE',
        ], $overrides));
    }

    public function test_index_embeds_paciente_sede_and_tipo(): void
    {
        $this->actingAsStaff();
        $paciente = $this->paciente(['dni' => '30111222']);
        $sede = $this->sede('Pinamar');
        $tipo = $this->tipo('PLASMA', 60);

        $this->turno([
            'paciente_id' => $paciente->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
        ]);

        $response = $this->getJson('/api/turnos');

        $response->assertStatus(200);
        $response->assertJsonPath('0.paciente.id', $paciente->id);
        $response->assertJsonPath('0.paciente.dni', '30111222');
        $response->assertJsonPath('0.paciente.nombre', 'Ana');
        $response->assertJsonPath('0.paciente.apellido', 'Gomez');
        $response->assertJsonPath('0.sede.nombre', 'Pinamar');
        $response->assertJsonPath('0.tipo.codigo', 'PLASMA');

        // Backward-compatible foreign keys must stay in the payload.
        $response->assertJsonPath('0.paciente_id', $paciente->id);
        $response->assertJsonPath('0.sede_id', $sede->id);
        $response->assertJsonPath('0.tipo_id', $tipo->id);
    }

    public function test_index_serializes_legacy_turno_without_tipo_as_null(): void
    {
        $this->actingAsStaff();

        $this->turno(['tipo_id' => null]);

        $response = $this->getJson('/api/turnos');

        $response->assertStatus(200);
        $response->assertJsonPath('0.tipo', null);
        $response->assertJsonPath('0.tipo_id', null);

        // The key must be present (not dropped) so clients can rely on it.
        $this->assertArrayHasKey('tipo', $response->json('0'));
    }

    public function test_index_filters_by_paciente_and_fecha(): void
    {
        $this->actingAsStaff();
        $match = $this->paciente();
        $other = $this->paciente();

        $this->turno(['paciente_id' => $match->id, 'fecha' => '2026-06-01']);
        $this->turno(['paciente_id' => $other->id, 'fecha' => '2026-06-01', 'hora' => '11:00']);
        $this->turno(['paciente_id' => $match->id, 'fecha' => '2026-06-02']);

        $byPaciente = $this->getJson('/api/turnos?paciente_id='.$match->id);
        $byPaciente->assertStatus(200);
        $this->assertCount(2, $byPaciente->json());
        $byPaciente->assertJsonPath('0.paciente.id', $match->id);

        $byFecha = $this->getJson('/api/turnos?fecha=2026-06-02');
        $byFecha->assertStatus(200);
        $this->assertCount(1, $byFecha->json());
        // Date-only shape: clients render the slot date directly.
        $byFecha->assertJsonPath('0.fecha', '2026-06-02');
    }

    public function test_store_response_includes_relations(): void
    {
        config(['agenda_capacidad.minutos_por_dia' => 480, 'agenda_capacidad.duracion_minima' => 15]);
        $this->actingAsStaff();
        $paciente = $this->paciente();
        $sede = $this->sede();
        $tipo = $this->tipo('SANGRE', 30);

        $response = $this->postJson('/api/turnos', [
            'paciente_id' => $paciente->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
            'fecha' => '2026-06-01',
            'hora' => '10:00',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('paciente.id', $paciente->id);
        $response->assertJsonPath('sede.nombre', $sede->nombre);
        $response->assertJsonPath('tipo.codigo', 'SANGRE');
    }

    public function test_update_response_includes_relations(): void
    {
        $this->actingAsStaff();
        $turno = $this->turno();

        $response = $this->patchJson('/api/turnos/'.$turno->id, ['estado' => 'ATENDIDO']);

        $response->assertStatus(200);
        $response->assertJsonPath('estado', 'ATENDIDO');
        $response->assertJsonPath('paciente.id', $turno->paciente_id);
        $response->assertJsonPath('sede.id', $turno->sede_id);
        $response->assertJsonPath('tipo.codigo', 'SANGRE');
    }

    public function test_index_requires_authentication(): void
    {
        $this->getJson('/api/turnos')->assertStatus(401);
    }
}
