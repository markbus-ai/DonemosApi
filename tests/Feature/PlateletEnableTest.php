<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\AutorizacionExtraordinaria;
use App\Models\HabilitacionPlaqueta;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\TipoDonacion;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * WU-B behaviour: platelet-enable lifecycle (default window, expiry boundary,
 * no auto-renew, disable, re-enable) and the overridable 409 gate merged into
 * the existing donation `forzar` path.
 */
class PlateletEnableTest extends TestCase
{
    use RefreshDatabase;

    private const FECHA = '2026-05-04';

    private Usuario $staff;

    protected function setUp(): void
    {
        parent::setUp();
        $this->staff = $this->usuario();
        Sanctum::actingAs($this->staff, ['*'], 'staff');
    }

    private function usuario(): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);

        return Usuario::create([
            'username' => 'user_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);
    }

    private function paciente(): Paciente
    {
        $aptitud = Aptitud::firstOrCreate(['tipo' => 'APTO']);

        return Paciente::create([
            'dni' => (string) fake()->unique()->numerify('########'),
            'nombre' => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'telefono' => fake()->phoneNumber(),
            'aptitud_id' => $aptitud->id,
        ]);
    }

    private function tipo(string $codigo, bool $esAferesis = false): TipoDonacion
    {
        return TipoDonacion::firstOrCreate(
            ['codigo' => $codigo],
            ['nombre' => $codigo, 'es_aferesis' => $esAferesis],
        );
    }

    public function test_default_enable_is_six_months_and_active_today(): void
    {
        Carbon::setTestNow('2026-06-30');
        $paciente = $this->paciente();

        $response = $this->postJson('/api/pacientes/'.$paciente->dni.'/habilitacion-plaquetas');

        $response->assertStatus(201);

        $this->assertDatabaseHas('habilitaciones_plaquetas', [
            'paciente_id' => $paciente->id,
            'desde' => '2026-06-30',
            'hasta' => '2026-12-30',
            'created_by' => $this->staff->id,
        ]);

        $this->assertSame(
            1,
            HabilitacionPlaqueta::where('paciente_id', $paciente->id)->vigente('2026-06-30')->count(),
        );

        Carbon::setTestNow();
    }

    public function test_explicit_hasta_is_honored(): void
    {
        Carbon::setTestNow('2026-06-30');
        $paciente = $this->paciente();

        $this->postJson('/api/pacientes/'.$paciente->dni.'/habilitacion-plaquetas', [
            'hasta' => '2027-01-31',
        ])->assertStatus(201);

        $this->assertDatabaseHas('habilitaciones_plaquetas', [
            'paciente_id' => $paciente->id,
            'hasta' => '2027-01-31',
        ]);

        Carbon::setTestNow();
    }

    public function test_expiry_boundary_day_before_is_active_and_expiry_day_inactive(): void
    {
        $paciente = $this->paciente();

        HabilitacionPlaqueta::create([
            'paciente_id' => $paciente->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-12-31',
        ]);

        $this->assertSame(1, HabilitacionPlaqueta::where('paciente_id', $paciente->id)->vigente('2026-12-30')->count());
        $this->assertSame(0, HabilitacionPlaqueta::where('paciente_id', $paciente->id)->vigente('2026-12-31')->count());
    }

    public function test_expired_enable_is_never_auto_renewed_by_a_read(): void
    {
        $paciente = $this->paciente();

        $enable = HabilitacionPlaqueta::create([
            'paciente_id' => $paciente->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-02-01',
        ]);

        // Evaluating platelets later must not mutate the stored window.
        $this->assertSame(0, HabilitacionPlaqueta::where('paciente_id', $paciente->id)->vigente('2026-06-01')->count());

        $fresh = $enable->fresh();
        $this->assertSame('2026-01-01', $fresh->desde);
        $this->assertSame('2026-02-01', $fresh->hasta);
        $this->assertSame(1, HabilitacionPlaqueta::where('paciente_id', $paciente->id)->count());
    }

    public function test_re_enable_closes_prior_active_row_and_extends_without_shortening(): void
    {
        Carbon::setTestNow('2026-06-30');
        $paciente = $this->paciente();

        // Unexpired prior window ending far in the future.
        $prior = HabilitacionPlaqueta::create([
            'paciente_id' => $paciente->id,
            'desde' => '2026-01-01',
            'hasta' => '2027-06-30',
        ]);

        $response = $this->postJson('/api/pacientes/'.$paciente->dni.'/habilitacion-plaquetas');

        $response->assertStatus(201);

        // History is append-only: prior closed on the new `desde`, new row appended.
        $prior->refresh();
        $this->assertSame('2026-06-30', $prior->hasta);

        $active = HabilitacionPlaqueta::where('paciente_id', $paciente->id)
            ->vigente('2026-06-30')
            ->firstOrFail();

        $this->assertNotSame($prior->id, $active->id);
        // The new window must not shorten the unexpired prior expiry.
        $this->assertSame('2027-06-30', $active->hasta);
        $this->assertSame(2, HabilitacionPlaqueta::where('paciente_id', $paciente->id)->count());

        Carbon::setTestNow();
    }

    public function test_disable_closes_the_active_enable_without_deleting(): void
    {
        Carbon::setTestNow('2026-06-30');
        $paciente = $this->paciente();

        HabilitacionPlaqueta::create([
            'paciente_id' => $paciente->id,
            'desde' => '2026-01-01',
            'hasta' => '2027-01-01',
        ]);

        $this->deleteJson('/api/pacientes/'.$paciente->dni.'/habilitacion-plaquetas')
            ->assertStatus(204);

        $this->assertSame(0, HabilitacionPlaqueta::where('paciente_id', $paciente->id)->vigente('2026-06-30')->count());
        $this->assertDatabaseHas('habilitaciones_plaquetas', [
            'paciente_id' => $paciente->id,
            'hasta' => '2026-06-30',
        ]);

        Carbon::setTestNow();
    }

    public function test_index_lists_enable_history(): void
    {
        $paciente = $this->paciente();

        $paciente->habilitacionesPlaquetas()->createMany([
            ['desde' => '2026-01-01', 'hasta' => '2026-02-01'],
            ['desde' => '2026-03-01', 'hasta' => '2026-04-01'],
        ]);

        $response = $this->getJson('/api/pacientes/'.$paciente->dni.'/habilitacion-plaquetas');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
    }

    public function test_platelet_donation_without_enable_returns_409_and_persists_nothing(): void
    {
        $paciente = $this->paciente();
        $tipo = $this->tipo('PLAQUETAS', true);

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'plaquetas' => 250000,
            'hematocrito' => 40.5,
        ]);

        $response->assertStatus(409);
        $codes = array_column($response->json('warnings'), 'code');
        $this->assertContains('PLAQUETAS_NO_HABILITADAS', $codes);
        $this->assertDatabaseCount('donaciones', 0);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 0);
    }

    public function test_expired_enable_does_not_satisfy_the_platelet_gate(): void
    {
        Carbon::setTestNow('2026-06-30');
        $paciente = $this->paciente();
        $tipo = $this->tipo('PLAQUETAS', true);

        HabilitacionPlaqueta::create([
            'paciente_id' => $paciente->id,
            'desde' => '2026-01-01',
            'hasta' => '2026-02-01',
        ]);

        $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'plaquetas' => 250000,
            'hematocrito' => 40.5,
        ])->assertStatus(409);

        Carbon::setTestNow();
    }

    public function test_active_enable_allows_platelet_donation_without_forzar(): void
    {
        $paciente = $this->paciente();
        $tipo = $this->tipo('PLAQUETAS', true);

        HabilitacionPlaqueta::create([
            'paciente_id' => $paciente->id,
            'desde' => '2026-01-01',
            'hasta' => '2027-01-01',
        ]);

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'plaquetas' => 250000,
            'hematocrito' => 40.5,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('donaciones', 1);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 0);
    }

    public function test_forced_platelet_donation_creates_donation_and_audited_authorization(): void
    {
        $paciente = $this->paciente();
        $tipo = $this->tipo('PLAQUETAS', true);

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'plaquetas' => 250000,
            'hematocrito' => 40.5,
            'forzar' => true,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('donaciones', 1);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 1);
        $this->assertDatabaseHas('autorizaciones_extraordinarias', [
            'paciente_id' => $paciente->id,
            'usuario_id' => $this->staff->id,
        ]);

        // The audited motivo reflects the platelet gate code.
        $this->assertSame('Plaquetas no habilitadas', AutorizacionExtraordinaria::firstOrFail()->motivo);
    }

    public function test_non_apheresis_donation_is_unaffected_by_the_platelet_gate(): void
    {
        $paciente = $this->paciente();
        $tipo = $this->tipo('SANGRE', false);

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('donaciones', 1);
        $this->assertDatabaseCount('autorizaciones_extraordinarias', 0);
    }

    public function test_platelet_gate_follows_the_code_not_the_apheresis_flag(): void
    {
        // PLASMA is apheresis but is not the platelet product: no gate.
        $paciente = $this->paciente();
        $tipo = $this->tipo('PLASMA', true);

        $response = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'plaquetas' => 250000,
            'hematocrito' => 40.5,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseCount('donaciones', 1);
    }

    public function test_platelet_gate_requires_authentication(): void
    {
        $paciente = $this->paciente();
        $tipo = $this->tipo('PLAQUETAS', true);

        // Rebuild the request without a token on the staff guard.
        $this->app['auth']->forgetGuards();

        $this->postJson('/api/pacientes/'.$paciente->dni.'/habilitacion-plaquetas')
            ->assertStatus(401);

        $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'forzar' => true,
        ])->assertStatus(401);

        $this->assertDatabaseCount('donaciones', 0);
    }

    public function test_real_donation_number_flow_is_not_broken_by_the_gate(): void
    {
        // Guard against route/order regressions: the self-exclusion route keeps
        // resolving by number while the platelet gate merges into creation.
        $paciente = $this->paciente();
        $tipo = $this->tipo('PLAQUETAS', true);

        HabilitacionPlaqueta::create([
            'paciente_id' => $paciente->id,
            'desde' => '2026-01-01',
            'hasta' => '2027-01-01',
        ]);

        $created = $this->postJson('/api/donaciones', [
            'paciente_id' => $paciente->id,
            'tipo_id' => $tipo->id,
            'componentes' => [$tipo->id],
            'fecha' => self::FECHA,
            'plaquetas' => 250000,
            'hematocrito' => 40.5,
        ]);

        $created->assertStatus(201);

        $numero = $created->json('numero_donacion');
        $this->assertNotNull($numero);

        $this->postJson('/api/donaciones/'.$numero.'/autoexclusion', ['motivo' => 'Voluntaria'])
            ->assertStatus(201);
    }
}
