<?php

namespace Tests\Unit\Rules;

use App\Rules\ClinicalSignRules;
use Tests\TestCase;

/**
 * Pure clinical-sign rules: config-driven sourced gates (overridable warnings),
 * integrity errors (non-overridable) and the apheresis prior-hemogram
 * precondition. Thresholds come from config/clinical_signs.php, never literals.
 */
class ClinicalSignRulesTest extends TestCase
{
    public function test_low_hemoglobin_emits_sourced_warning(): void
    {
        $result = (new ClinicalSignRules)->check(['hemoglobina' => 11.0], false);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['HEMOGLOBINA_BAJA'], array_column($result['warnings'], 'code'));
    }

    public function test_low_weight_emits_sourced_warning(): void
    {
        $result = (new ClinicalSignRules)->check(['peso_donante' => 48.0], false);

        $this->assertSame([], $result['errors']);
        $this->assertSame(['PESO_BAJO'], array_column($result['warnings'], 'code'));
    }

    public function test_boundary_values_are_accepted(): void
    {
        $result = (new ClinicalSignRules)->check([
            'hemoglobina' => 12.5,
            'peso_donante' => 50.0,
        ], false);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_both_sourced_values_low_emit_both_warnings(): void
    {
        $result = (new ClinicalSignRules)->check([
            'hemoglobina' => 10.0,
            'peso_donante' => 45.0,
        ], false);

        $codes = array_column($result['warnings'], 'code');
        sort($codes);

        $this->assertSame(['HEMOGLOBINA_BAJA', 'PESO_BAJO'], $codes);
        $this->assertSame([], $result['errors']);
    }

    public function test_absent_sourced_values_never_gate(): void
    {
        $result = (new ClinicalSignRules)->check([], false);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_capture_only_signs_never_gate(): void
    {
        // Values far outside any plausible range, but thresholds are unverified
        // so they are captured only and must never warn or error.
        $result = (new ClinicalSignRules)->check([
            'hematocrito' => 1.0,
            'plaquetas' => 1,
            'presion_sistolica' => 300,
            'presion_diastolica' => 10,
            'frecuencia_cardiaca' => 300,
        ], false);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_numeric_string_values_are_compared(): void
    {
        $result = (new ClinicalSignRules)->check([
            'hemoglobina' => '11.0',
            'peso_donante' => '49.5',
        ], false);

        $codes = array_column($result['warnings'], 'code');
        sort($codes);

        $this->assertSame(['HEMOGLOBINA_BAJA', 'PESO_BAJO'], $codes);
        $this->assertSame([], $result['errors']);
    }

    public function test_negative_sign_is_integrity_error(): void
    {
        $result = (new ClinicalSignRules)->check(['frecuencia_cardiaca' => -5], false);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('frecuencia_cardiaca', implode(' ', $result['errors']));
        $this->assertSame([], $result['warnings']);
    }

    public function test_non_numeric_sign_is_integrity_error(): void
    {
        $result = (new ClinicalSignRules)->check(['hemoglobina' => 'no-numerico'], false);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('hemoglobina', implode(' ', $result['errors']));
    }

    public function test_diastolic_greater_than_systolic_is_integrity_error(): void
    {
        $result = (new ClinicalSignRules)->check([
            'presion_sistolica' => 80,
            'presion_diastolica' => 120,
        ], false);

        $this->assertNotEmpty($result['errors']);
        $this->assertStringContainsString('diast', implode(' ', $result['errors']));
    }

    public function test_equal_pressure_values_are_accepted(): void
    {
        $result = (new ClinicalSignRules)->check([
            'presion_sistolica' => 120,
            'presion_diastolica' => 120,
        ], false);

        $this->assertSame([], $result['errors']);
    }

    public function test_single_pressure_value_is_not_an_integrity_error(): void
    {
        // Partial pressure is tolerated: the model accessor reads null until
        // both values exist. Only an inverted pair is malformed.
        $result = (new ClinicalSignRules)->check(['presion_sistolica' => 120], false);

        $this->assertSame([], $result['errors']);
    }

    public function test_apheresis_requires_platelets_and_hematocrit(): void
    {
        $onlyHematocrito = (new ClinicalSignRules)->check(['hematocrito' => 40.0], true);
        $onlyPlaquetas = (new ClinicalSignRules)->check(['plaquetas' => 250000], true);
        $none = (new ClinicalSignRules)->check([], true);

        foreach ([$onlyHematocrito, $onlyPlaquetas, $none] as $result) {
            $this->assertNotEmpty($result['errors']);
            $this->assertStringContainsString('aféresis', implode(' ', $result['errors']));
        }
    }

    public function test_apheresis_with_complete_hemogram_has_no_error(): void
    {
        $result = (new ClinicalSignRules)->check([
            'plaquetas' => 250000,
            'hematocrito' => 42.0,
        ], true);

        $this->assertSame([], $result['errors']);
    }

    public function test_whole_blood_is_exempt_from_apheresis_precondition(): void
    {
        $result = (new ClinicalSignRules)->check([], false);

        $this->assertSame([], $result['errors']);
    }

    public function test_threshold_comes_from_config_not_literal(): void
    {
        $custom = [
            'sourced' => [
                'hemoglobina' => ['min' => 8.0, 'code' => 'HEMOGLOBINA_BAJA', 'unit' => 'g/dL'],
            ],
            'capture_only' => [],
        ];

        $result = (new ClinicalSignRules($custom))->check(['hemoglobina' => 11.0], false);

        $this->assertSame([], $result['warnings'], 'A min of 8.0 must not flag an 11.0 value.');
    }

    public function test_raising_config_threshold_flags_previously_accepted_value(): void
    {
        $custom = [
            'sourced' => [
                'hemoglobina' => ['min' => 15.0, 'code' => 'HEMOGLOBINA_BAJA', 'unit' => 'g/dL'],
            ],
            'capture_only' => [],
        ];

        $result = (new ClinicalSignRules($custom))->check(['hemoglobina' => 14.0], false);

        $this->assertSame(['HEMOGLOBINA_BAJA'], array_column($result['warnings'], 'code'));
    }

    public function test_default_config_is_read_from_laravel_config(): void
    {
        config(['clinical_signs.sourced.hemoglobina.min' => 9.0]);

        $result = (new ClinicalSignRules)->check(['hemoglobina' => 11.0], false);

        $this->assertSame([], $result['warnings']);
    }

    public function test_field_moved_to_capture_only_stops_gating(): void
    {
        $custom = [
            'sourced' => [],
            'capture_only' => ['hemoglobina', 'peso_donante'],
        ];

        $result = (new ClinicalSignRules($custom))->check([
            'hemoglobina' => 5.0,
            'peso_donante' => 30.0,
        ], false);

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }
}
