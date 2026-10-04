<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\Sede;
use App\Models\Turno;
use Database\Seeders\DemoSeeder;
use Database\Seeders\SedeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Runtime proof for the dev/demo seed.
 *
 * The seed must look alive (patients plus same-day turnos) without ever
 * pretending to hold clinical truth: clinical-sign columns stay NULL and the
 * deferral / self-exclusion / platelet-enable tables stay empty. It is gated
 * behind SEED_DEMO_DATA so real deploys never receive demo patients.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    private const CLINICAL_COLUMNS = [
        'hemoglobina',
        'hematocrito',
        'plaquetas',
        'presion_sistolica',
        'presion_diastolica',
        'frecuencia_cardiaca',
        'peso_donante',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Standard lookup data the demo seed resolves against (APTO, sedes,
        // donation types) — exactly what a real `db:seed` gives it.
        $this->seed(SedeSeeder::class);
        $this->seed(\Database\Seeders\AptitudSeeder::class);
        $this->seed(\Database\Seeders\TipoDonacionSeeder::class);
    }

    private function runDemoSeeder(): void
    {
        $this->seed(DemoSeeder::class);
    }

    public function test_seed_creates_demo_patients_with_expected_shape(): void
    {
        $this->runDemoSeeder();

        $count = Paciente::where('apellido', 'Paciente')->count();
        $this->assertGreaterThanOrEqual(8, $count);
        $this->assertLessThanOrEqual(12, $count);

        $aptoId = DB::table('aptitudes')->where('tipo', 'APTO')->value('id');
        $this->assertNotNull($aptoId);
        $this->assertSame(0, Paciente::where('aptitud_id', '!=', $aptoId)->count());

        $demo = Paciente::where('apellido', 'Paciente')->orderBy('dni')->get();

        // Obviously-demo DNI range, all unique.
        $this->assertSame(
            $demo->count(),
            $demo->pluck('dni')->unique()->count(),
        );
        $demo->each(function (Paciente $paciente): void {
            $this->assertMatchesRegularExpression('/^900000[0-9]{2}$/', $paciente->dni);
        });

        // Alternating sex and plausible height, both present on every row.
        $demo->each(function (Paciente $paciente): void {
            $this->assertContains($paciente->sexo, ['M', 'F']);
            $this->assertGreaterThanOrEqual(165.0, (float) $paciente->altura);
            $this->assertLessThanOrEqual(180.0, (float) $paciente->altura);
        });
        $this->assertSame(['F', 'M'], $demo->pluck('sexo')->unique()->sort()->values()->all());
    }

    public function test_seed_creates_today_turnos_linked_to_demo_patients(): void
    {
        $this->runDemoSeeder();

        $sede = Sede::where('nombre', 'Sede Central')->firstOrFail();
        $today = now()->toDateString();

        $todayCount = Turno::where('sede_id', $sede->id)
            ->whereDate('fecha', $today)
            ->count();
        $this->assertGreaterThan(0, $todayCount);

        $demoIds = Paciente::where('apellido', 'Paciente')->pluck('id');

        Turno::query()->with('paciente')->get()->each(function (Turno $turno) use ($demoIds): void {
            $this->assertContains($turno->paciente_id, $demoIds);
            $this->assertSame('PENDIENTE', $turno->estado);
            $this->assertNotNull($turno->tipo_id);
        });
    }

    public function test_seed_respects_sede_fecha_hora_unique(): void
    {
        $this->runDemoSeeder();

        $dupes = DB::table('turnos')
            ->select('sede_id', 'fecha', 'hora', DB::raw('COUNT(*) as c'))
            ->groupBy('sede_id', 'fecha', 'hora')
            ->having('c', '>', 1)
            ->get();

        $this->assertCount(0, $dupes, 'Seeded turnos must not collide on the unique slot.');
    }

    public function test_seed_never_writes_clinical_signs_or_fake_metrics(): void
    {
        $this->runDemoSeeder();

        foreach (self::CLINICAL_COLUMNS as $column) {
            $this->assertSame(
                0,
                DB::table('donaciones')->whereNotNull($column)->count(),
                "Demo seed must leave donaciones.{$column} NULL.",
            );
        }
    }

    public function test_seed_leaves_deferral_selfexclusion_and_platelet_tables_empty(): void
    {
        $this->runDemoSeeder();

        $this->assertSame(0, DB::table('restricciones')->count());
        $this->assertSame(0, DB::table('autoexclusiones')->count());
        $this->assertSame(0, DB::table('habilitaciones_plaquetas')->count());
        $this->assertSame(0, DB::table('autorizaciones_extraordinarias')->count());
    }

    public function test_seed_is_idempotent(): void
    {
        $this->runDemoSeeder();

        $pacientes = Paciente::count();
        $turnos = Turno::count();

        $this->runDemoSeeder();

        $this->assertSame($pacientes, Paciente::count());
        $this->assertSame($turnos, Turno::count());
    }

    public function test_database_seeder_skips_demo_data_when_flag_is_off(): void
    {
        $this->assertFalse(filter_var(env('SEED_DEMO_DATA', false), FILTER_VALIDATE_BOOL));

        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $this->assertSame(0, Paciente::count(), 'Production flow must not seed demo patients.');
        $this->assertSame(0, Turno::count(), 'Production flow must not seed demo turnos.');
        $this->assertDatabaseCount('sedes', 4);
    }
}
