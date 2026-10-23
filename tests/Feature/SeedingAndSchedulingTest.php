<?php

namespace Tests\Feature;

use App\Models\Motivo;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Seed completeness and safety, and the housekeeping schedule.
 */
class SeedingAndSchedulingTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_seed_provides_motivos(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, Motivo::count());
        $this->assertSame(Motivo::count(), Motivo::distinct()->count('codigo'));
    }

    public function test_seeding_refuses_default_passwords_in_production(): void
    {
        $this->app['env'] = 'production';
        putenv('SEED_STAFF_PASSWORD');

        $this->expectException(\RuntimeException::class);
        (new UsuarioSeeder)->run();
    }

    public function test_expired_tokens_are_pruned_daily(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');

        $this->assertStringContainsString('sanctum:prune-expired', $commands);
    }
}
