<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\Restriccion;
use App\Models\TipoDonacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * "Today" is the hemocentro's calendar day (America/Argentina/Buenos_Aires),
 * not UTC: 22:30 in Argentina is already tomorrow in UTC. Deferral dates are
 * judged against that day too.
 */
class HemocentroDayTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private Motivo $motivo;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.business_timezone' => 'America/Argentina/Buenos_Aires']);
        $this->travelTo('2026-10-22 13:00:00');
        $this->motivo = Motivo::factory()->create();
        $this->actingAsStaff();
    }

    public function test_today_follows_the_hemocentro_timezone(): void
    {
        $sangre = TipoDonacion::factory()->sangre()->create();
        $this->travelTo('2026-10-23 01:30:00');
        $paciente = Paciente::factory()->create();

        $this->postJson('/api/turnos', ['paciente_id' => $paciente->id, 'fecha' => '2026-10-22', 'hora' => '23:00'])
            ->assertCreated();
        $this->postJson('/api/donaciones', ['paciente_id' => $paciente->id, 'tipo_id' => $sangre->id, 'componentes' => [$sangre->id], 'fecha' => '2026-10-23'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('fecha');
    }

    public function test_disabling_platelets_in_the_argentine_evening_takes_effect_today(): void
    {
        $paciente = Paciente::factory()->create();
        $this->postJson("/api/pacientes/{$paciente->dni}/habilitacion-plaquetas", [])->assertCreated();

        $this->travelTo('2026-10-23 01:30:00');
        $this->deleteJson("/api/pacientes/{$paciente->dni}/habilitacion-plaquetas")->assertNoContent();

        $this->assertDatabaseHas('habilitaciones_plaquetas', ['paciente_id' => $paciente->id, 'hasta' => '2026-10-22']);
    }

    public function test_lifting_a_deferral_in_the_evening_closes_it_today(): void
    {
        Aptitud::firstOrCreate(['tipo' => 'APTO']);
        $paciente = Paciente::factory()->create();
        $this->postJson("/api/pacientes/{$paciente->dni}/restriccion", ['motivo_id' => $this->motivo->id, 'desde' => '2026-10-01'])
            ->assertCreated();

        $this->travelTo('2026-10-23 01:30:00');
        $this->patchJson("/api/pacientes/{$paciente->dni}/aptitud", ['tipo' => 'APTO'])->assertOk();

        $this->assertDatabaseHas('restricciones', ['paciente_id' => $paciente->id, 'hasta' => '2026-10-22']);
    }

    public function test_backdated_deferral_cannot_lift_an_active_one(): void
    {
        Aptitud::firstOrCreate(['tipo' => 'NO_APTO']);
        $paciente = Paciente::factory()->create();
        $this->postJson("/api/pacientes/{$paciente->dni}/restriccion", ['motivo_id' => $this->motivo->id, 'desde' => '2026-04-01'])
            ->assertCreated();

        $this->postJson("/api/pacientes/{$paciente->dni}/restriccion", ['motivo_id' => $this->motivo->id, 'desde' => '2020-01-01', 'hasta' => '2020-01-02'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('desde');

        $this->patchJson("/api/pacientes/{$paciente->dni}/aptitud", ['tipo' => 'NO_APTO', 'motivo_id' => $this->motivo->id, 'desde' => '2020-01-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('desde');

        $this->assertTrue(Restriccion::where('paciente_id', $paciente->id)->vigente('2026-10-22')->exists());
    }
}
