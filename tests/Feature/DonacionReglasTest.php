<?php

namespace Tests\Feature;

use App\Models\AutorizacionExtraordinaria;
use App\Models\Paciente;
use App\Models\TipoDonacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * Donation rules seen through the API: NO_APTO and interval warnings need
 * `forzar`, and a forced donation is audited with the operator's motivo.
 */
class DonacionReglasTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    private TipoDonacion $sangre;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 10:00:00');
        $this->sangre = TipoDonacion::factory()->sangre()->create();
        $this->actingAsStaff();
    }

    private function donar(Paciente $paciente, array $overrides = []): TestResponse
    {
        return $this->postJson('/api/donaciones', array_merge([
            'paciente_id' => $paciente->id,
            'tipo_id' => $this->sangre->id,
            'componentes' => [$this->sangre->id],
            'fecha' => '2026-10-22',
        ], $overrides));
    }

    public function test_no_apto_donor_needs_forzar(): void
    {
        $this->donar(Paciente::factory()->noApto()->create())
            ->assertStatus(409)
            ->assertJsonFragment(['code' => 'BLOQUEO_NO_APTO']);
    }

    public function test_interval_applies_to_back_dated_donations(): void
    {
        $paciente = Paciente::factory()->masculino()->create();

        $this->donar($paciente, ['fecha' => '2026-10-20'])->assertCreated();

        $this->donar($paciente, ['fecha' => '2026-10-19'])
            ->assertStatus(409)
            ->assertJsonFragment(['code' => 'WARNING_INTERVALO_SEXO']);
    }

    public function test_unknown_sex_uses_the_strictest_interval(): void
    {
        $paciente = Paciente::factory()->create(['sexo' => null]);

        $this->donar($paciente)->assertCreated();

        $this->donar($paciente)
            ->assertStatus(409)
            ->assertJsonFragment(['code' => 'WARNING_INTERVALO_SEXO']);
    }

    public function test_forcing_a_donation_requires_a_motivo_that_is_audited(): void
    {
        $paciente = Paciente::factory()->create();

        $this->donar($paciente, ['hemoglobina' => 11, 'forzar' => true])
            ->assertStatus(422)
            ->assertJsonValidationErrors('motivo');

        $this->donar($paciente, ['hemoglobina' => 11, 'forzar' => true, 'motivo' => 'Autorizado por la Dra. Pérez'])
            ->assertCreated();

        $this->assertStringContainsString('Autorizado por la Dra. Pérez', AutorizacionExtraordinaria::latest('id')->value('motivo'));
    }
}
