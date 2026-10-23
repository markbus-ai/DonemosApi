<?php

namespace Tests\Feature;

use App\Models\Sede;
use App\Models\Turno;
use App\Models\Usuario;
use Database\Seeders\AptitudSeeder;
use Database\Seeders\DemoSeeder;
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

    public function test_demo_agenda_starts_on_the_local_day_in_the_evening(): void
    {
        config(['app.business_timezone' => 'America/Argentina/Buenos_Aires']);
        // 23:30 in Argentina is already the next day in UTC.
        $this->travelTo('2026-10-23 02:30:00');

        $this->seed(AptitudSeeder::class);
        $this->seed(SedeSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertStringStartsWith('2026-10-22', (string) Turno::min('fecha'));
    }
}
