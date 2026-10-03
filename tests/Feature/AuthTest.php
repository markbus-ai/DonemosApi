<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    private function makeUsuario(string $username = 'AD', string $password = '1234'): Usuario
    {
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);

        return Usuario::create([
            'username' => $username,
            'password_hash' => Hash::make($password),
            'rol_id' => $rol->id,
        ]);
    }

    // -----------------------------------------------------------------
    // Unit 1: Foundation — authenticatable Usuario, guard config, seeder
    // -----------------------------------------------------------------

    public function test_usuario_implements_authenticatable_contract(): void
    {
        $usuario = $this->makeUsuario();

        $this->assertInstanceOf(Authenticatable::class, $usuario);
    }

    public function test_usuario_hides_password_hash_from_serialization(): void
    {
        $usuario = $this->makeUsuario();

        $this->assertArrayNotHasKey('password_hash', $usuario->toArray());
    }

    public function test_usuario_uses_password_hash_as_auth_password(): void
    {
        $usuario = $this->makeUsuario('AB', '4321');

        $this->assertSame('password_hash', $usuario->getAuthPasswordName());
        $this->assertTrue(Hash::check('4321', $usuario->getAuthPassword()));
    }

    public function test_usuario_can_issue_personal_access_tokens(): void
    {
        $usuario = $this->makeUsuario();

        $token = $usuario->createToken('staff');

        $this->assertNotEmpty($token->plainTextToken);
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $usuario->id,
            'tokenable_type' => Usuario::class,
            'name' => 'staff',
        ]);
    }

    public function test_staff_guard_uses_sanctum_driver_and_usuarios_provider(): void
    {
        $this->assertSame('sanctum', config('auth.guards.staff.driver'));
        $this->assertSame('usuarios', config('auth.guards.staff.provider'));
        $this->assertSame(Usuario::class, config('auth.providers.usuarios.model'));
        $this->assertSame([], config('sanctum.guard'));
        $this->assertSame(720, (int) config('sanctum.expiration'));
    }

    public function test_usuario_seeder_creates_hashed_staff_login(): void
    {
        $this->seed(UsuarioSeeder::class);

        $username = env('SEED_STAFF_USERNAME', 'AD');
        $password = env('SEED_STAFF_PASSWORD', '1234');

        $usuario = Usuario::where('username', $username)->firstOrFail();

        $this->assertNotNull($usuario->rol_id);
        $this->assertTrue(Hash::check($password, $usuario->getAuthPassword()));
    }
}
