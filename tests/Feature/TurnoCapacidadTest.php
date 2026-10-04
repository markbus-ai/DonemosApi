<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\AutorizacionExtraordinaria;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Turno;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * WU-2 HTTP surface: duration-sum capacity blocks over-limit bookings with a
 * 409 `CAPACIDAD_EXCEDIDA`, is never bypassed by `forzar`, keeps exact hours
 * and the existing uniques, and is scoped per sede.
 */
class TurnoCapacidadTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA = '2026-06-01';

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
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ]);
    }

    private function sede(string $nombre): Sede
    {
        return Sede::firstOrCreate(['nombre' => $nombre], ['activa' => true]);
    }

    private function tipo(int $duracion, string $codigo = 'SANGRE'): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(
            ['codigo' => $codigo],
            ['nombre' => $codigo, 'duracion_minutos' => $duracion],
        );
    }

    private function seedTurno(Sede $sede, TipoDonacion $tipo, string $hora): Turno
    {
        return Turno::create([
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
            'fecha' => self::FECHA,
            'hora' => $hora,
            'estado' => 'PENDIENTE',
        ]);
    }

    public function test_accepts_booking_that_fits_exactly_remaining_capacity(): void
    {
        config(['agenda_capacidad.minutos_por_dia' => 60, 'agenda_capacidad.duracion_minima' => 15]);
        $sede = $this->sede('Sede Central');
        $tipo = $this->tipo(30);

        $this->seedTurno($sede, $tipo, '09:00');

        $response = $this->postJson('/api/turnos', [
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
            'fecha' => self::FECHA,
            'hora' => '10:00',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('turnos', 2);
    }

    public function test_rejects_over_capacity_with_409_and_no_row(): void
    {
        config(['agenda_capacidad.minutos_por_dia' => 60, 'agenda_capacidad.duracion_minima' => 15]);
        $sede = $this->sede('Sede Central');
        $short = $this->tipo(30, 'SANGRE');
        $long = $this->tipo(60, 'PLAQUETAS');

        $this->seedTurno($sede, $short, '09:00');

        $response = $this->postJson('/api/turnos', [
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $sede->id,
            'tipo_id' => $long->id,
            'fecha' => self::FECHA,
            'hora' => '10:00',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'CAPACIDAD_EXCEDIDA');
        $this->assertDatabaseCount('turnos', 1);
    }

    public function test_forzar_cannot_bypass_capacity(): void
    {
        config(['agenda_capacidad.minutos_por_dia' => 60, 'agenda_capacidad.duracion_minima' => 15]);
        $sede = $this->sede('Sede Central');
        $short = $this->tipo(30, 'SANGRE');
        $long = $this->tipo(60, 'PLAQUETAS');

        $this->seedTurno($sede, $short, '09:00');

        $response = $this->postJson('/api/turnos', [
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $sede->id,
            'tipo_id' => $long->id,
            'fecha' => self::FECHA,
            'hora' => '10:00',
            'forzar' => true,
            'motivo' => 'criterio médico',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'CAPACIDAD_EXCEDIDA');
        $this->assertDatabaseCount('turnos', 1);
        // A blocked capacity gate must not mint an extraordinary authorization.
        $this->assertSame(0, AutorizacionExtraordinaria::count());
    }

    public function test_capacity_is_per_sede(): void
    {
        config(['agenda_capacidad.minutos_por_dia' => 60, 'agenda_capacidad.duracion_minima' => 15]);
        $sedeA = $this->sede('Sede Central');
        $sedeB = $this->sede('Pinamar');
        $tipo = $this->tipo(60);

        $this->seedTurno($sedeA, $tipo, '09:00');

        $response = $this->postJson('/api/turnos', [
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $sedeB->id,
            'tipo_id' => $tipo->id,
            'fecha' => self::FECHA,
            'hora' => '10:00',
        ]);

        $response->assertStatus(201);
    }

    public function test_exact_hour_is_preserved(): void
    {
        config(['agenda_capacidad.minutos_por_dia' => 480, 'agenda_capacidad.duracion_minima' => 15]);
        $sede = $this->sede('Sede Central');
        $tipo = $this->tipo(30);

        $response = $this->postJson('/api/turnos', [
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
            'fecha' => self::FECHA,
            'hora' => '10:07',
        ]);

        $response->assertStatus(201);
        $hora = Turno::where('sede_id', $sede->id)->latest('id')->first()->hora;

        $this->assertSame('10:07', $hora->format('H:i'));
    }

    public function test_duplicate_sede_fecha_hora_is_rejected(): void
    {
        config(['agenda_capacidad.minutos_por_dia' => 480, 'agenda_capacidad.duracion_minima' => 15]);
        $sede = $this->sede('Sede Central');
        $tipo = $this->tipo(30);

        $this->seedTurno($sede, $tipo, '10:00');

        $this->expectException(QueryException::class);

        Turno::create([
            'paciente_id' => $this->paciente()->id,
            'sede_id' => $sede->id,
            'tipo_id' => $tipo->id,
            'fecha' => self::FECHA,
            'hora' => '10:00',
            'estado' => 'PENDIENTE',
        ]);
    }

    public function test_sede_id_is_resolved_from_staff_home_sede(): void
    {
        config(['agenda_capacidad.minutos_por_dia' => 60, 'agenda_capacidad.duracion_minima' => 15]);
        $sede = $this->sede('Sede Central');
        $tipo = $this->tipo(60);

        $this->staff->update(['sede_id' => $sede->id]);
        $this->seedTurno($sede, $tipo, '09:00');

        // No sede_id in the payload: the staff home sede must resolve and the
        // capacity check must still block.
        $response = $this->postJson('/api/turnos', [
            'paciente_id' => $this->paciente()->id,
            'tipo_id' => $tipo->id,
            'fecha' => self::FECHA,
            'hora' => '10:00',
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'CAPACIDAD_EXCEDIDA');
    }
}
