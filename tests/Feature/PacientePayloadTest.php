<?php

namespace Tests\Feature;

use App\Models\Motivo;
use App\Models\Paciente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * Patient payload contracts the clients rely on: raw fields plus aptitud on
 * show, the observation flag, and accent/case-insensitive search.
 */
class PacientePayloadTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 13:00:00');
        $this->actingAsStaff();
    }

    public function test_show_includes_aptitud_and_keeps_raw_fields(): void
    {
        $paciente = Paciente::factory()->create();

        $this->getJson('/api/pacientes/'.$paciente->dni)
            ->assertOk()
            ->assertJsonPath('aptitud_id', $paciente->aptitud_id)
            ->assertJsonPath('aptitud.tipo', 'APTO');
    }

    public function test_patient_payload_flags_an_observation(): void
    {
        $paciente = Paciente::factory()->create();
        $motivo = Motivo::factory()->create();

        $this->getJson('/api/pacientes/'.$paciente->dni)->assertJsonPath('activeObservacion', false);
        $this->postJson("/api/pacientes/{$paciente->dni}/observacion", ['motivo_id' => $motivo->id, 'desde' => '2026-10-22'])->assertCreated();

        $this->getJson('/api/pacientes/'.$paciente->dni)->assertJsonPath('activeObservacion', true);
        $this->getJson('/api/pacientes?search='.$paciente->dni)->assertJsonPath('data.0.activeObservacion', true);
    }

    public function test_patient_search_is_case_and_accent_insensitive(): void
    {
        Paciente::factory()->create(['nombre' => 'Ángela', 'apellido' => 'GÓMEZ']);

        $this->getJson('/api/pacientes?search=gómez')->assertOk()->assertJsonCount(1, 'data');
    }
}
