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
use App\Models\Zona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Usage-flow contracts the clients rely on: audited forced donations, turnos
 * closed by the donation that attends them, agenda from a date, and the user
 * payload naming its sede and zona.
 */
class DemoFlowsTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    private Usuario $staff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 13:00:00');

        Aptitud::firstOrCreate(['tipo' => 'APTO']);
        $zona = Zona::create(['nombre' => 'Costa Atlántica']);
        $this->sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $this->sede->update(['zona_id' => $zona->id, 'codigo_localidad' => '1']);

        $this->staff = Usuario::create([
            'username' => 'staff_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => Rol::firstOrCreate(['nombre' => 'staff'])->id,
            'sede_id' => $this->sede->id,
        ]);

        Sanctum::actingAs($this->staff, ['*'], 'staff');
    }

    private function paciente(array $overrides = []): Paciente
    {
        return Paciente::create(array_merge([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '2254000000',
            'sexo' => 'F',
            'aptitud_id' => Aptitud::where('tipo', 'APTO')->value('id'),
        ], $overrides));
    }

    private function sangre(): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(['codigo' => 'SANGRE'], ['nombre' => 'SANGRE']);
    }

    private function donar(Paciente $paciente, array $overrides = [])
    {
        $tipo = $this->sangre();

        return $this->postJson('/api/donaciones', array_merge([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
        ], $overrides));
    }

    public function test_forcing_a_donation_requires_a_motivo_that_is_audited(): void
    {
        $paciente = $this->paciente();

        $this->donar($paciente, ['hemoglobina' => 11, 'forzar' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->donar($paciente, ['hemoglobina' => 11, 'forzar' => true, 'motivo' => 'Autorizado por la Dra. Pérez'])
            ->assertCreated();

        $this->assertStringContainsString('Autorizado por la Dra. Pérez', AutorizacionExtraordinaria::latest('id')->value('motivo'));
    }

    public function test_donation_attends_the_pending_turno_of_the_day(): void
    {
        $paciente = $this->paciente();
        $turno = Turno::create(['paciente_id' => $paciente->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-10-22', 'hora' => '09:00', 'estado' => 'PENDIENTE']);
        $otroDia = Turno::create(['paciente_id' => $paciente->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-12-22', 'hora' => '09:00', 'estado' => 'PENDIENTE']);

        $this->donar($paciente)->assertCreated();

        $this->assertSame('ATENDIDO', $turno->fresh()->estado);
        $this->assertSame('PENDIENTE', $otroDia->fresh()->estado);
    }

    public function test_agenda_can_start_from_a_date(): void
    {
        $paciente = $this->paciente();
        Turno::create(['paciente_id' => $paciente->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-10-07', 'hora' => '09:00', 'estado' => 'PENDIENTE']);
        Turno::create(['paciente_id' => $paciente->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-10-23', 'hora' => '09:00', 'estado' => 'PENDIENTE']);

        $this->getJson('/api/turnos?desde=2026-10-22')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.fecha', '2026-10-23');
    }

    public function test_user_payload_names_sede_and_zona(): void
    {
        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('sede', 'Sede Central')
            ->assertJsonPath('zona', null);
    }
}
