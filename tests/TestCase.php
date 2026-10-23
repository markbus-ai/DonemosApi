<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Drop the database guards (triggers / CHECKs) for tests that exercise
     * older migrations or deliberately store legacy-shaped rows.
     */
    protected function withoutDatabaseGuards(): void
    {
        (require base_path('database/migrations/2026_10_23_000002_add_database_guards.php'))->down();
    }
}
