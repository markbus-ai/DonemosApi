<?php

namespace Tests\Feature;

use App\Models\AutorizacionExtraordinaria;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Turno;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * Agenda contracts: slots, capacity, one-way states, reminders, the agenda
 * from a date and the donation that attends its turno.
 */
class TurnoAgendaTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.business_timezone' => 'America/Argentina/Buenos_Aires']);
        $this->travelTo('2026-10-22 13:00:00');
        $this->sede = Sede::porDefecto();
        $this->sede->update(['codigo_localidad' => '001']);
        $this->actingAsStaff($this->sede);
    }

    private function turno(string $fecha, string $hora = '09:00', array $overrides = []): Turno
    {
        return Turno::factory()->create(['sede_id' => $this->sede->id, 'fecha' => $fecha, 'hora' => $hora, ...$overrides]);
    }

    public function test_taken_slot_is_409_horario_ocupado_not_500(): void
    {
        $body = ['fecha' => '2026-12-01', 'hora' => '10:00'];

        $this->postJson('/api/turnos', [...$body, 'paciente_id' => Paciente::factory()->create()->id])->assertCreated();

        $this->postJson('/api/turnos', [...$body, 'paciente_id' => Paciente::factory()->create()->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'HORARIO_OCUPADO');

        $this->postJson('/api/turnos', [...$body, 'paciente_id' => Paciente::factory()->create()->id, 'forzar' => true, 'motivo' => 'x'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'HORARIO_OCUPADO');
    }

    public function test_cancelled_turnos_do_not_consume_capacity(): void
    {
        for ($i = 0; $i < 32; $i++) {
            $this->turno('2026-12-02', sprintf('%02d:%02d', 8 + intdiv($i * 15, 60), ($i * 15) % 60), ['estado' => 'CANCELADO']);
        }

        $this->postJson('/api/turnos', ['paciente_id' => Paciente::factory()->create()->id, 'fecha' => '2026-12-02', 'hora' => '17:00'])
            ->assertCreated();
    }

    public function test_a_cancelled_slot_can_be_booked_again(): void
    {
        $turno = $this->turno('2026-11-02');
        $this->patchJson("/api/turnos/{$turno->id}", ['estado' => 'CANCELADO'])->assertOk();

        $this->postJson('/api/turnos', ['paciente_id' => Paciente::factory()->create()->id, 'fecha' => '2026-11-02', 'hora' => '09:00'])
            ->assertCreated();
    }

    public function test_turno_state_transitions_are_one_way(): void
    {
        $turno = $this->turno('2026-12-03', '10:00');

        $this->patchJson("/api/turnos/{$turno->id}", ['estado' => 'CANCELADO'])->assertOk();
        $this->patchJson("/api/turnos/{$turno->id}", ['estado' => 'ATENDIDO'])->assertStatus(422);
        $this->patchJson("/api/turnos/{$turno->id}", ['estado' => 'PENDIENTE'])->assertStatus(422);
    }

    public function test_reminder_only_for_future_pending_turnos(): void
    {
        $cancelado = Turno::factory()->cancelado()->create(['sede_id' => $this->sede->id, 'fecha' => '2026-12-04', 'hora' => '10:00']);
        $pasado = $this->turno('2026-01-04', '10:00');

        $this->postJson("/api/turnos/{$cancelado->id}/recordatorio")->assertStatus(422);
        $this->postJson("/api/turnos/{$pasado->id}/recordatorio")->assertStatus(422);
    }

    public function test_forced_turno_keeps_the_staff_motivo(): void
    {
        $paciente = Paciente::factory()->create();
        $this->turno('2026-11-01', '09:00', ['paciente_id' => $paciente->id]);

        $this->postJson('/api/turnos', ['paciente_id' => $paciente->id, 'fecha' => '2026-11-02', 'hora' => '09:00', 'forzar' => true, 'motivo' => 'Pedido del médico de guardia'])
            ->assertCreated();

        $this->assertStringContainsString('Pedido del médico de guardia', AutorizacionExtraordinaria::latest('id')->value('motivo'));
    }

    public function test_agenda_can_start_from_a_date(): void
    {
        $paciente = Paciente::factory()->create();
        $this->turno('2026-10-07', '09:00', ['paciente_id' => $paciente->id]);
        $this->turno('2026-10-23', '09:00', ['paciente_id' => $paciente->id]);

        $this->getJson('/api/turnos?desde=2026-10-22')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.fecha', '2026-10-23');
    }

    public function test_turno_fecha_and_hora_are_stored_in_canonical_formats(): void
    {
        $turno = $this->turno('2026-11-04', '08:30:00');

        $raw = DB::table('turnos')->where('id', $turno->id)->first();
        $this->assertSame('2026-11-04', $raw->fecha);
        $this->assertSame('08:30', $raw->hora);
        $this->assertSame('2026-11-04', $turno->fresh()->fecha->toDateString());
    }

    public function test_donation_attends_the_pending_turno_of_the_day(): void
    {
        $paciente = Paciente::factory()->create();
        $hoy = $this->turno('2026-10-22', '09:00', ['paciente_id' => $paciente->id]);
        $otroDia = $this->turno('2026-12-22', '09:00', ['paciente_id' => $paciente->id]);
        $sangre = TipoDonacion::factory()->sangre()->create();

        $this->postJson('/api/donaciones', ['paciente_id' => $paciente->id, 'tipo_id' => $sangre->id, 'componentes' => [$sangre->id]])
            ->assertCreated();

        $this->assertSame('ATENDIDO', $hoy->fresh()->estado);
        $this->assertSame('PENDIENTE', $otroDia->fresh()->estado);
    }

    public function test_report_range_includes_its_last_day(): void
    {
        $this->actingAsAdminSede($this->sede);
        $this->turno('2026-11-05', '08:00');

        $this->getJson('/api/reportes/resumen?desde=2026-11-01&hasta=2026-11-05')
            ->assertOk()
            ->assertJsonPath('turnos_por_estado.PENDIENTE', 1);
    }
}
