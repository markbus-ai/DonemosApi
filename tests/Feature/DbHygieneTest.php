<?php

namespace Tests\Feature;

use App\Models\Aptitud;
use App\Models\Paciente;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\Usuario;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Database review hygiene: default sede by flag, soft-disabled users, unused
 * framework tables, token pruning, production seed safety and indexes.
 */
class DbHygieneTest extends TestCase
{
    use RefreshDatabase;

    private function staff(?int $sedeId, bool $activo = true): Usuario
    {
        return Usuario::create([
            'username' => 'u'.random_int(100, 999),
            'password_hash' => Hash::make('1234'),
            'rol_id' => Rol::firstOrCreate(['nombre' => 'staff'])->id,
            'sede_id' => $sedeId,
            'activo' => $activo,
        ]);
    }

    public function test_default_sede_comes_from_a_flag_not_its_name(): void
    {
        $this->travelTo('2026-10-22 13:00:00');
        Sede::query()->update(['es_default' => false]);
        $central = Sede::firstOrCreate(['nombre' => 'Sede Central'], ['activa' => true]);
        $central->update(['nombre' => 'Hemocentro Costa']);
        $otra = Sede::create(['nombre' => 'Pinamar', 'activa' => true, 'codigo_localidad' => '003', 'es_default' => true]);

        Sanctum::actingAs($this->staff(null), ['*'], 'staff');
        $paciente = Paciente::create(['dni' => '30111222', 'nombre' => 'A', 'apellido' => 'B', 'telefono' => '2254000000', 'aptitud_id' => Aptitud::firstOrCreate(['tipo' => 'APTO'])->id]);

        $this->postJson('/api/turnos', ['paciente_id' => $paciente->id, 'fecha' => '2026-11-01', 'hora' => '09:00'])
            ->assertCreated()
            ->assertJsonPath('sede_id', $otra->id);
    }

    public function test_an_inactive_user_cannot_log_in_or_use_a_token(): void
    {
        $usuario = $this->staff(null, false);

        $this->postJson('/api/auth/login', ['username' => $usuario->username, 'password' => '1234'])->assertStatus(401);

        $token = $usuario->createToken('staff')->plainTextToken;
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_unused_framework_user_tables_are_gone(): void
    {
        $this->assertFalse(Schema::hasTable('users'));
        $this->assertFalse(Schema::hasTable('password_reset_tokens'));
    }

    public function test_expired_tokens_are_pruned_daily(): void
    {
        $commands = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');

        $this->assertStringContainsString('sanctum:prune-expired', $commands);
    }

    public function test_seeding_refuses_default_passwords_in_production(): void
    {
        $this->app['env'] = 'production';
        putenv('SEED_STAFF_PASSWORD');

        $this->expectException(\RuntimeException::class);
        (new UsuarioSeeder)->run();
    }

    public function test_query_paths_are_indexed(): void
    {
        $this->assertTrue(Schema::hasIndex('donaciones', ['paciente_id', 'fecha']));
        $this->assertTrue(Schema::hasIndex('observaciones', ['paciente_id']));
        $this->assertTrue(Schema::hasIndex('autorizaciones_extraordinarias', ['paciente_id']));
        $this->assertTrue(Schema::hasIndex('recordatorios_turno', ['turno_id']));
        $this->assertTrue(Schema::hasIndex('componentes_donacion', ['tipo_id']));
    }

    public function test_patient_search_is_case_insensitive(): void
    {
        Sanctum::actingAs($this->staff(null), ['*'], 'staff');
        Paciente::create(['dni' => '30111222', 'nombre' => 'Ángela', 'apellido' => 'GÓMEZ', 'telefono' => '2254000000', 'aptitud_id' => Aptitud::firstOrCreate(['tipo' => 'APTO'])->id]);

        $this->getJson('/api/pacientes?search=gómez')->assertOk()->assertJsonCount(1, 'data');
    }
}
