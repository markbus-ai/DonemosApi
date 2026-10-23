<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use App\Services\Whatsapp\WhatsappVinculacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Process;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Linking the hemocentro's WhatsApp from the app: the API starts the WhatsPlay
 * bridge in the background and serves its QR privately to admins only.
 */
class WhatsappVinculacionTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = storage_path('framework/testing/whatsapp_'.uniqid());
        config(['whatsapp.estado_dir' => $this->dir, 'whatsapp.whatsplay.dir' => '/opt/whatsapp-skill']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function actingAsRol(string $rol): void
    {
        Sanctum::actingAs(Usuario::create([
            'username' => 'u_'.uniqid(),
            'password_hash' => Hash::make('1234'),
            'rol_id' => Rol::firstOrCreate(['nombre' => $rol])->id,
        ]), ['*'], 'staff');
    }

    private function escribirEstado(array $estado): void
    {
        File::ensureDirectoryExists($this->dir);
        File::put($this->dir.'/estado.json', json_encode($estado));
    }

    public function test_only_admins_can_link_or_see_the_qr(): void
    {
        $this->actingAsRol('staff');

        $this->getJson('/api/whatsapp/estado')->assertStatus(403);
        $this->postJson('/api/whatsapp/vincular')->assertStatus(403);
    }

    public function test_state_is_unverified_before_any_link_attempt(): void
    {
        $this->actingAsRol(Usuario::ROL_ADMIN_ZONA);

        $this->getJson('/api/whatsapp/estado')
            ->assertOk()
            ->assertExactJson(['estado' => 'sin_verificar', 'qr' => null, 'detalle' => null, 'actualizado' => null]);
    }

    public function test_linking_starts_the_bridge_in_the_background(): void
    {
        $this->actingAsRol(Usuario::ROL_ADMIN_ZONA);
        Process::fake(['*' => Process::result(output: "4242\n")]);

        $this->postJson('/api/whatsapp/vincular')->assertStatus(202)->assertJsonPath('estado', 'iniciando');

        Process::assertRan(fn ($process) => str_contains(implode(' ', (array) $process->command), 'whatsplay_turnos.py')
            && str_contains(implode(' ', (array) $process->command), 'vincular'));
        $this->assertSame('4242', trim(File::get($this->dir.'/pid')));
    }

    public function test_the_qr_is_served_while_waiting_for_the_scan(): void
    {
        $this->actingAsRol(Usuario::ROL_ADMIN_SEDE);
        $this->escribirEstado(['estado' => 'esperando_qr', 'qr' => 'iVBORw0KGgo=', 'detalle' => null, 'actualizado' => 1700000000]);
        File::put($this->dir.'/pid', (string) getmypid());

        $this->getJson('/api/whatsapp/estado')
            ->assertOk()
            ->assertJsonPath('estado', 'esperando_qr')
            ->assertJsonPath('qr', 'iVBORw0KGgo=');
    }

    public function test_a_dead_bridge_while_waiting_is_reported_as_error(): void
    {
        $this->actingAsRol(Usuario::ROL_ADMIN_SEDE);
        $this->escribirEstado(['estado' => 'esperando_qr', 'qr' => 'iVBORw0KGgo=', 'detalle' => null, 'actualizado' => 1700000000]);
        File::put($this->dir.'/pid', '999999');

        $this->getJson('/api/whatsapp/estado')
            ->assertOk()
            ->assertJsonPath('estado', 'error')
            ->assertJsonPath('qr', null);
    }

    public function test_a_second_link_attempt_while_one_runs_is_a_conflict(): void
    {
        $this->actingAsRol(Usuario::ROL_ADMIN_SEDE);
        $this->escribirEstado(['estado' => 'esperando_qr', 'qr' => 'x', 'detalle' => null, 'actualizado' => 1]);
        File::put($this->dir.'/pid', (string) getmypid());
        Process::fake();

        $this->postJson('/api/whatsapp/vincular')->assertStatus(409);
        Process::assertNothingRan();
    }

    public function test_the_qr_never_leaves_through_whatsplays_public_server(): void
    {
        $script = File::get(base_path('scripts/whatsplay_turnos.py'));

        $this->assertStringContainsString('state_manager.show_qr_window = lambda', $script);
        $this->assertTrue(app(WhatsappVinculacion::class) instanceof WhatsappVinculacion);
    }
}
