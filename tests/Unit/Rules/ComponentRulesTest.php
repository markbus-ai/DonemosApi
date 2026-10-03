<?php

namespace Tests\Unit\Rules;

use App\Rules\ComponentRules;
use Tests\TestCase;

/**
 * Pure component-set limits: per-code maxima, empty and unknown rejection.
 */
class ComponentRulesTest extends TestCase
{
    public function test_empty_set_is_rejected(): void
    {
        $errors = (new ComponentRules)->check([]);

        $this->assertNotEmpty($errors);
    }

    public function test_unknown_code_is_rejected(): void
    {
        $errors = (new ComponentRules)->check(['SANGRE', 'DESCONOCIDO']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('DESCONOCIDO', implode(' ', $errors));
    }

    public function test_within_limits_is_accepted(): void
    {
        $rules = new ComponentRules;

        $this->assertSame([], $rules->check(['SANGRE']));
        $this->assertSame([], $rules->check(['PLASMA', 'PLAQUETAS', 'PLAQUETAS', 'PLAQUETAS']));
        $this->assertSame([], $rules->check(['SANGRE', 'PLASMA', 'PLAQUETAS', 'PLAQUETAS', 'PLAQUETAS']));
    }

    public function test_over_limit_platelets_is_rejected(): void
    {
        $errors = (new ComponentRules)->check(['PLAQUETAS', 'PLAQUETAS', 'PLAQUETAS', 'PLAQUETAS']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('PLAQUETAS', implode(' ', $errors));
    }

    public function test_over_limit_plasma_is_rejected(): void
    {
        $errors = (new ComponentRules)->check(['PLASMA', 'PLASMA']);

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('PLASMA', implode(' ', $errors));
    }
}
