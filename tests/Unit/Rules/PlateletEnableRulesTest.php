<?php

namespace Tests\Unit\Rules;

use App\Models\Aptitud;
use App\Models\HabilitacionPlaqueta;
use App\Models\Paciente;
use App\Rules\PlateletEnableRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pure platelet-enable window rules. The window is `desde <= D < hasta`
 * (desde inclusive, hasta exclusive) and matches HabilitacionPlaqueta::scopeVigente
 * exactly — one boundary contract, two surfaces.
 */
class PlateletEnableRulesTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_default_window_is_six_months_from_desde(): void
    {
        $rules = new PlateletEnableRules;

        $this->assertSame(6, $rules->meses());
        $this->assertSame('2026-12-30', $rules->defaultHasta('2026-06-30'));
    }

    public function test_default_window_is_config_driven_not_literal(): void
    {
        config(['platelet_enable.meses' => 3]);

        $this->assertSame(3, (new PlateletEnableRules)->meses());
        $this->assertSame('2026-09-30', (new PlateletEnableRules)->defaultHasta('2026-06-30'));
    }

    public function test_is_active_desde_is_inclusive(): void
    {
        $rules = new PlateletEnableRules;

        $this->assertTrue($rules->isActive('2026-01-01', '2026-04-01', '2026-01-01'));
    }

    public function test_is_active_day_before_desde_is_inactive(): void
    {
        $rules = new PlateletEnableRules;

        $this->assertFalse($rules->isActive('2026-01-01', '2026-04-01', '2025-12-31'));
    }

    public function test_is_active_day_before_hasta_is_active(): void
    {
        $rules = new PlateletEnableRules;

        $this->assertTrue($rules->isActive('2026-01-01', '2026-04-01', '2026-03-31'));
    }

    public function test_is_active_on_hasta_day_is_inactive(): void
    {
        $rules = new PlateletEnableRules;

        $this->assertFalse($rules->isActive('2026-01-01', '2026-04-01', '2026-04-01'));
    }

    public function test_is_active_is_inactive_without_a_window(): void
    {
        $rules = new PlateletEnableRules;

        $this->assertFalse($rules->isActive(null, '2026-04-01', '2026-03-31'));
        $this->assertFalse($rules->isActive('2026-01-01', null, '2026-03-31'));
        $this->assertFalse($rules->isActive(null, null, '2026-03-31'));
    }

    public function test_resolve_hasta_uses_requested_value_when_supplied(): void
    {
        $rules = new PlateletEnableRules;

        $this->assertSame(
            '2027-01-31',
            $rules->resolveHasta('2027-01-31', '2026-06-30', null),
        );
    }

    public function test_resolve_hasta_defaults_to_desde_plus_meses(): void
    {
        $rules = new PlateletEnableRules;

        $this->assertSame(
            '2026-12-30',
            $rules->resolveHasta(null, '2026-06-30', null),
        );
    }

    public function test_resolve_hasta_never_shortens_an_unexpired_prior_hasta(): void
    {
        $rules = new PlateletEnableRules;

        // Requested window ends sooner than the prior unexpired one; keep the later.
        $this->assertSame(
            '2027-06-30',
            $rules->resolveHasta('2026-12-31', '2026-06-30', '2027-06-30'),
        );
    }

    public function test_resolve_hasta_ignores_a_prior_hasta_already_passed(): void
    {
        $rules = new PlateletEnableRules;

        $this->assertSame(
            '2026-12-30',
            $rules->resolveHasta(null, '2026-06-30', '2026-01-01'),
        );
    }

    public function test_scope_vigente_and_is_active_agree_on_the_boundary(): void
    {
        $paciente = $this->paciente();
        $rules = new PlateletEnableRules;

        $enable = HabilitacionPlaqueta::create([
            'paciente_id' => $paciente->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-04-01',
        ]);

        foreach (['2025-12-31', '2026-01-01', '2026-03-31', '2026-04-01', '2026-05-01'] as $dia) {
            $scope = HabilitacionPlaqueta::whereKey($enable->id)->vigente($dia)->exists();
            $pure = $rules->isActive($enable->desde, $enable->hasta, $dia);

            $this->assertSame(
                $scope,
                $pure,
                "Boundary disagreement between scopeVigente and isActive on {$dia}.",
            );
        }
    }
}
