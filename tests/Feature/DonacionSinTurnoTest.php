<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\ComponenteDonacion;
use App\Models\Donacion;
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
 * WU-4 regression: the capacity slice must not make a turno mandatory. A valid
 * walk-in donation with no prior turno still succeeds with 201.
 */
class DonacionSinTurnoTest extends TestCase
{
    use RefreshDatabase;

    private Usuario $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = $this->staff();
        Sanctum::actingAs($this->staff, ['*'], 'staff');
    }

    private function staff(): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);

        return Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);
    }

    private function paciente(): Paciente
    {
        $aptitud = Aptitud::firstOrCreate(['tipo' => 'APTO']);

        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ]);
    }

    public function test_donation_without_any_turno_succeeds(): void
    {
        Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $paciente = $this->paciente();
        $tipo = TipoDonacion::firstOrCreate(
            ['codigo' => 'SANGRE'],
            ['nombre' => 'SANGRE', 'duracion_minutos' => 30],
        );

        $this->assertSame(0, Turno::count());

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [['tipo_id' => $tipo->id]],
        ]);

        $response->assertStatus(201);
        $this->assertSame(1, Donacion::count());
        $this->assertSame(0, Turno::count());
        $this->assertSame(1, ComponenteDonacion::count());
    }

    public function test_full_agenda_does_not_block_a_walk_in_donation(): void
    {
        $sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $paciente = $this->paciente();
        $tipo = TipoDonacion::firstOrCreate(
            ['codigo' => 'SANGRE'],
            ['nombre' => 'SANGRE', 'duracion_minutos' => 30],
        );

        // Saturate the agenda for today; donations are independent of capacity.
        config(['agenda_capacidad.minutos_por_dia' => 30]);
        Turno::create([
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
            'fecha' => now()->toDateString(),
            'hora' => '09:00',
            'estado' => 'PENDIENTE',
        ]);

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [['tipo_id' => $tipo->id]],
        ]);

        $response->assertStatus(201);
    }

    public function test_api_turno_create_persists_tipo_and_uses_its_duration(): void
    {
        config(['agenda_capacidad.minutos_por_dia' => 60, 'agenda_capacidad.duracion_minima' => 15]);
        $sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $paciente = $this->paciente();
        $tipo = TipoDonacion::firstOrCreate(
            ['codigo' => 'PLASMA'],
            ['nombre' => 'PLASMA', 'duracion_minutos' => 60, 'es_aferesis' => true],
        );

        $response = $this->postJson('/api/turnos', [
            'paciente_id' => $paciente->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
            'fecha' => '2026-06-01',
            'hora' => '10:00',
        ]);

        $response->assertStatus(201);

        $turno = Turno::latest('id')->first();
        $this->assertSame($tipo->id, $turno->tipo_id);
        $this->assertTrue($turno->tipoDonacion->is($tipo));

        // A second 60-minute booking exceeds the 60-minute daily limit.
        $this->postJson('/api/turnos', [
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
            'fecha' => '2026-06-01',
            'hora' => '11:00',
        ])->assertStatus(409)->assertJsonPath('code', 'CAPACIDAD_EXCEDIDA');
    }
}
