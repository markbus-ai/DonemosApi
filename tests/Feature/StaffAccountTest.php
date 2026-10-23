<?php

namespace Tests\Feature;

use App\Models\Paciente;
use App\Models\Sede;
use App\Models\Usuario;
use App\Models\Zona;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsStaff;
use Tests\TestCase;

/**
 * Staff accounts: soft-disabled users lose access, the user payload names its
 * sede and zona, and an operator without a sede books at the flagged default.
 */
class StaffAccountTest extends TestCase
{
    use ActsAsStaff, RefreshDatabase;

    public function test_an_inactive_user_cannot_log_in_or_use_a_token(): void
    {
        $usuario = Usuario::factory()->inactivo()->create();

        $this->postJson('/api/auth/login', ['username' => $usuario->username, 'password' => '1234'])->assertStatus(401);

        $token = $usuario->createToken('staff')->plainTextToken;
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_user_payload_names_sede_and_zona(): void
    {
        $sede = Sede::porDefecto();
        $sede->update(['zona_id' => Zona::factory()->create(['nombre' => 'Costa Atlántica'])->id]);
        $this->actingAsStaff($sede);

        $this->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('sede', 'Sede Central')
            ->assertJsonPath('zona', null);
    }

    public function test_default_sede_comes_from_a_flag_not_its_name(): void
    {
        $this->travelTo('2026-10-22 13:00:00');
        Sede::query()->update(['es_default' => false]);
        Sede::porDefecto()->update(['nombre' => 'Hemocentro Costa']);
        $otra = Sede::factory()->porDefecto()->create(['nombre' => 'Pinamar', 'codigo_localidad' => '003']);
        $this->actingAsUsuario(Usuario::factory()->create(['sede_id' => null]));
        $paciente = Paciente::factory()->create();

        $this->postJson('/api/turnos', ['paciente_id' => $paciente->id, 'fecha' => '2026-11-01', 'hora' => '09:00'])
            ->assertCreated()
            ->assertJsonPath('sede_id', $otra->id);
    }

    public function test_admin_factories_scope_the_statistics_picker(): void
    {
        $zona = Zona::factory()->create();
        $enZona = Sede::factory()->create(['zona_id' => $zona->id]);
        Sede::factory()->create();

        $this->actingAsAdminZona($zona);
        $this->getJson('/api/reportes/sedes')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $enZona->id);

        $this->actingAsAdminSede($enZona);
        $this->getJson('/api/reportes/sedes')->assertOk()->assertJsonCount(1)->assertJsonPath('0.id', $enZona->id);

        $this->actingAsStaff();
        $this->getJson('/api/reportes/sedes')->assertForbidden();
    }
}
