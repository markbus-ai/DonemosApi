<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Motivo;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Turno;
use App\Models\Usuario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Database review fixes: seed completeness, slot reuse, stored date formats
 * and confidentiality of self-exclusion.
 */
class DbReviewTest extends TestCase
{
    use RefreshDatabase;

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-22 13:00:00');

        Aptitud::firstOrCreate(['tipo' => 'APTO']);
        $this->sede = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $this->sede->update(['codigo_localidad' => '001']);

        Sanctum::actingAs(Usuario::create([
            'username' => 'adm_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => Rol::firstOrCreate(['nombre' => Usuario::ROL_ADMIN_SEDE])->id,
            'sede_id' => $this->sede->id,
        ]), ['*'], 'staff');
    }

    private function paciente(): Paciente
    {
        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => 'Ana',
            'apellido' => 'Gomez',
            'telefono' => '2254000000',
            'sexo' => 'F',
            'aptitud_id' => Aptitud::where('tipo', 'APTO')->value('id'),
        ]);
    }

    public function test_fresh_seed_provides_motivos(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->assertGreaterThan(0, Motivo::count());
        $this->assertSame(Motivo::count(), Motivo::distinct()->count('codigo'));
    }

    public function test_a_cancelled_slot_can_be_booked_again(): void
    {
        $turno = Turno::create(['paciente_id' => $this->paciente()->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-11-02', 'hora' => '09:00', 'estado' => 'PENDIENTE']);
        $this->patchJson("/api/turnos/{$turno->id}", ['estado' => 'CANCELADO'])->assertOk();

        $this->postJson('/api/turnos', ['paciente_id' => $this->paciente()->id, 'fecha' => '2026-11-02', 'hora' => '09:00'])
            ->assertCreated();
    }

    public function test_two_active_turnos_still_cannot_share_a_slot_at_db_level(): void
    {
        Turno::create(['paciente_id' => $this->paciente()->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-11-03', 'hora' => '09:00', 'estado' => 'PENDIENTE']);

        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('turnos')->insert(['paciente_id' => $this->paciente()->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-11-03', 'hora' => '09:00', 'estado' => 'PENDIENTE', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_turno_fecha_and_hora_are_stored_in_canonical_formats(): void
    {
        $turno = Turno::create(['paciente_id' => $this->paciente()->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-11-04', 'hora' => '08:30:00', 'estado' => 'PENDIENTE']);

        $raw = DB::table('turnos')->where('id', $turno->id)->first();
        $this->assertSame('2026-11-04', $raw->fecha);
        $this->assertSame('08:30', $raw->hora);
        $this->assertSame('2026-11-04', $turno->fresh()->fecha->toDateString());
    }

    public function test_report_range_includes_its_last_day(): void
    {
        Turno::create(['paciente_id' => $this->paciente()->id, 'sede_id' => $this->sede->id, 'fecha' => '2026-11-05', 'hora' => '08:00', 'estado' => 'PENDIENTE']);

        $this->getJson('/api/reportes/resumen?desde=2026-11-01&hasta=2026-11-05')
            ->assertOk()
            ->assertJsonPath('turnos_por_estado.PENDIENTE', 1);
    }

    public function test_self_exclusion_does_not_leak_into_components_or_plain_text(): void
    {
        $tipo = TipoDonacion::firstOrCreate(['codigo' => 'SANGRE'], ['nombre' => 'SANGRE']);
        $numero = $this->postJson('/api/donaciones', ['paciente_id' => $this->paciente()->id, 'tipo_id' => $tipo->id, 'componentes' => [$tipo->id]])
            ->assertCreated()->json('donacion.numero_donacion') ?? DB::table('donaciones')->value('numero_donacion');

        $this->postJson("/api/donaciones/{$numero}/autoexclusion", ['motivo' => 'Conducta de riesgo reciente'])->assertCreated();

        $this->assertSame('DESCARTE_CONFIDENCIAL', DB::table('componentes_donacion')->value('motivo_descarte'));
        $this->assertStringNotContainsString('Conducta de riesgo', (string) DB::table('autoexclusiones')->value('motivo'));
    }
}
