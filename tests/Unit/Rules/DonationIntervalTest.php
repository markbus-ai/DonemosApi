<?php

namespace Tests\Unit\Rules;

use App\Models\Aptitud;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\TipoDonacion;
use App\Rules\DonationRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sex-aware minimum interval between whole-blood donations.
 *
 * Config-driven from config/donacion_intervalos.php: men 4 months, women
 * 6 months. When the patient's sex is unknown the check must NOT guess and
 * current behaviour (no interval warning) is preserved.
 */
class DonationIntervalTest extends TestCase
{
    use RefreshDatabase;

    private function tipo(): TipoDonacion
    {
        return TipoDonacion::create(['nombre' => 'SANGRE', 'codigo' => 'SANGRE']);
    }

    private function paciente(?string $sexo): Paciente
    {
        $aptitud = Aptitud::create(['tipo' => 'APTO']);

        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
            'sexo' => $sexo,
        ]);
    }

    private function donacion(Paciente $paciente, TipoDonacion $tipo, string $fecha): void
    {
        Donacion::create([
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'fecha' => $fecha,
        ]);
    }

    public function test_male_donating_after_four_months_is_allowed(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente('M');

        // Last donation 4 months + 1 day ago satisfies the 4-month interval.
        $this->donacion($paciente, $tipo, now()->subMonths(4)->subDay()->toDateString());

        $result = (new DonationRules)->check($paciente, $tipo, now()->toDateString());

        $this->assertTrue($result['allowed']);
        $this->assertSame(
            [],
            array_values(array_filter(
                array_column($result['warnings'], 'code'),
                fn (string $code) => $code === 'WARNING_INTERVALO_SEXO',
            )),
        );
    }

    public function test_male_donating_before_four_months_gets_interval_warning(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente('M');

        // Last donation 3 months ago is inside the 4-month window for men.
        $this->donacion($paciente, $tipo, now()->subMonths(3)->toDateString());

        $result = (new DonationRules)->check($paciente, $tipo, now()->toDateString());

        $this->assertFalse($result['allowed']);
        $this->assertContains('WARNING_INTERVALO_SEXO', array_column($result['warnings'], 'code'));
    }

    public function test_female_donating_after_five_months_still_gets_warning(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente('F');

        // 5 months clears the male window but not the 6-month female window.
        $this->donacion($paciente, $tipo, now()->subMonths(5)->toDateString());

        $result = (new DonationRules)->check($paciente, $tipo, now()->toDateString());

        $this->assertFalse($result['allowed']);
        $this->assertContains('WARNING_INTERVALO_SEXO', array_column($result['warnings'], 'code'));
    }

    public function test_female_donating_after_six_months_is_allowed(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente('F');

        $this->donacion($paciente, $tipo, now()->subMonths(6)->subDay()->toDateString());

        $result = (new DonationRules)->check($paciente, $tipo, now()->toDateString());

        $this->assertNotContains('WARNING_INTERVALO_SEXO', array_column($result['warnings'], 'code'));
    }

    public function test_unknown_sex_keeps_current_behaviour(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente(null);

        // 3 months would flag a male and 5 months a female; unknown sex must
        // never guess, so no sex-interval warning is emitted.
        $this->donacion($paciente, $tipo, now()->subMonths(3)->toDateString());

        $result = (new DonationRules)->check($paciente, $tipo, now()->toDateString());

        $this->assertTrue($result['allowed']);
        $this->assertNotContains('WARNING_INTERVALO_SEXO', array_column($result['warnings'], 'code'));
    }

    public function test_no_previous_donation_has_no_sex_interval_warning(): void
    {
        $tipo = $this->tipo();
        $paciente = $this->paciente('F');

        $result = (new DonationRules)->check($paciente, $tipo, now()->toDateString());

        $this->assertTrue($result['allowed']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_interval_months_come_from_config_not_literal(): void
    {
        config(['donacion_intervalos.min_por_sexo.M' => 1]);

        $tipo = $this->tipo();
        $paciente = $this->paciente('M');

        // Under the default 4-month config this would warn; a 1-month config
        // must accept a donation made one month + a day ago.
        $this->donacion($paciente, $tipo, now()->subMonths(1)->subDay()->toDateString());

        $result = (new DonationRules)->check($paciente, $tipo, now()->toDateString());

        $this->assertNotContains('WARNING_INTERVALO_SEXO', array_column($result['warnings'], 'code'));
    }
}
