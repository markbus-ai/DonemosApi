<?php

namespace Tests\Feature;

use App\Models\Sede;
use Database\Seeders\SedeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Runtime proof for the SedeSeeder locality-code mapping and its idempotency.
 *
 * Covers the donation-numbering spec scenario "Seeded": Central=1,
 * Pinamar=003, Mar de Ajó=030, Madariaga=004, with no duplicate rows when the
 * seeder runs more than once.
 */
class SedeSeederTest extends TestCase
{
    use RefreshDatabase;

    private function runSeeder(): void
    {
        $this->seed(SedeSeeder::class);
    }

    public function test_seeder_maps_every_known_sede_to_its_locality_code(): void
    {
        $this->runSeeder();

        $this->assertSame('1', Sede::where('nombre', 'Sede Central')->value('codigo_localidad'));
        $this->assertSame('003', Sede::where('nombre', 'Pinamar')->value('codigo_localidad'));
        $this->assertSame('030', Sede::where('nombre', 'Mar de Ajó')->value('codigo_localidad'));
        $this->assertSame('004', Sede::where('nombre', 'Madariaga')->value('codigo_localidad'));
    }

    public function test_seeder_is_idempotent_and_does_not_duplicate_sedes(): void
    {
        $this->runSeeder();
        $this->runSeeder();

        $this->assertSame(4, Sede::count());
        $this->assertSame(1, Sede::where('codigo_localidad', '003')->count());
        $this->assertSame(1, Sede::where('codigo_localidad', '030')->count());
        $this->assertSame(1, Sede::where('codigo_localidad', '004')->count());
        $this->assertSame('1', Sede::where('nombre', 'Sede Central')->value('codigo_localidad'));
    }

    public function test_seeder_keeps_only_the_central_sede_active(): void
    {
        $this->runSeeder();

        $this->assertTrue((bool) Sede::where('nombre', 'Sede Central')->value('activa'));
        $this->assertFalse((bool) Sede::where('nombre', 'Pinamar')->value('activa'));
    }
}
