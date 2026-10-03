<?php

namespace Tests\Unit\Support;

use App\Support\Bolemia;
use Tests\TestCase;

/**
 * Pure Nadler blood-volume calculation. Inputs: sex, height in cm and weight
 * in kg; output is total blood volume in millilitres (mL) or null when any
 * required input is missing.
 */
class BolemiaTest extends TestCase
{
    /**
     * Reference case (male): H = 1.80 m, W = 80 kg.
     * (0.3669*1.80^3 + 0.03219*80 + 0.6041) * 1000 = 5319.06 mL.
     */
    public function test_computes_male_bolemia_with_nadler_formula(): void
    {
        $ml = Bolemia::forMale(180.0, 80.0);

        $this->assertEqualsWithDelta(5319.06, $ml, 0.5);
    }

    /**
     * Second male case with different inputs forces real formula logic:
     * H = 1.70 m, W = 65 kg -> 4499.03 mL.
     */
    public function test_computes_male_bolemia_for_a_second_case(): void
    {
        $ml = Bolemia::forMale(170.0, 65.0);

        $this->assertEqualsWithDelta(4499.03, $ml, 0.5);
    }

    /**
     * Reference case (female): H = 1.65 m, W = 60 kg.
     * (0.3561*1.65^3 + 0.03308*60 + 0.1833) * 1000 = 3767.75 mL.
     */
    public function test_computes_female_bolemia_with_nadler_formula(): void
    {
        $ml = Bolemia::forFemale(165.0, 60.0);

        $this->assertEqualsWithDelta(3767.75, $ml, 0.5);
    }

    /**
     * Second female case: H = 1.58 m, W = 52 kg -> 3308.03 mL.
     */
    public function test_computes_female_bolemia_for_a_second_case(): void
    {
        $ml = Bolemia::forFemale(158.0, 52.0);

        $this->assertEqualsWithDelta(3308.03, $ml, 0.5);
    }

    public function test_returns_null_when_sex_is_missing(): void
    {
        $this->assertNull(Bolemia::compute(null, 175.0, 70.0));
    }

    public function test_returns_null_when_height_is_missing(): void
    {
        $this->assertNull(Bolemia::compute('M', null, 70.0));
    }

    public function test_returns_null_when_weight_is_missing(): void
    {
        $this->assertNull(Bolemia::compute('F', 165.0, null));
    }

    public function test_returns_null_for_unknown_sex(): void
    {
        $this->assertNull(Bolemia::compute('X', 175.0, 70.0));
    }

    public function test_compute_dispatches_male_and_female(): void
    {
        $this->assertEqualsWithDelta(Bolemia::forMale(180.0, 80.0), Bolemia::compute('M', 180.0, 80.0), 0.001);
        $this->assertEqualsWithDelta(Bolemia::forFemale(165.0, 60.0), Bolemia::compute('F', 165.0, 60.0), 0.001);
    }
}
