<?php

namespace Tests\Feature;

use App\Models\Sede;
use App\Models\Usuario;
use Database\Seeders\SedeSeeder;
use Database\Seeders\ZonaSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZonaSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeds_admins_scoped_to_sede_and_zona_idempotently(): void
    {
        $this->seed(SedeSeeder::class);
        $this->seed(ZonaSeeder::class);
        $this->assertSame(0, Usuario::whereIn('username', ['ADS', 'ADZ'])->count());

        ZonaSeeder::seedDemoAdmins();
        ZonaSeeder::seedDemoAdmins();

        $adminSede = Usuario::where('username', 'ADS')->firstOrFail();
        $adminZona = Usuario::where('username', 'ADZ')->firstOrFail();

        $this->assertSame([Sede::where('nombre', 'Sede Central')->value('id')], $adminSede->sedesVisiblesIds());
        $this->assertEqualsCanonicalizing(Sede::pluck('id')->all(), $adminZona->sedesVisiblesIds());
        $this->assertSame(1, Usuario::where('username', 'ADZ')->count());
    }
}
