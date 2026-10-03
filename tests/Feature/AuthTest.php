<?php

namespace Tests\Feature;

use App\Models\Rol;
use App\Models\Usuario;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
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

    // -----------------------------------------------------------------
    // Unit 2: Auth endpoints — login / me / logout / throttle
    // -----------------------------------------------------------------

    public function test_login_with_valid_credentials_returns_token_and_user(): void
    {
        $usuario = $this->makeUsuario('AD', '1234');

        $response = $this->postJson('/api/auth/login', [
            'username' => 'AD',
            'password' => '1234',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'token',
            'user' => ['id', 'username', 'rol_id', 'sede_id'],
        ]);
        $response->assertJsonPath('user.id', $usuario->id);
        $response->assertJsonPath('user.username', 'AD');
        $this->assertArrayNotHasKey('password_hash', $response->json('user'));
        $this->assertDatabaseCount('personal_access_tokens', 1);
    }

    public function test_login_with_wrong_password_and_unknown_user_share_one_uniform_401(): void
    {
        $this->makeUsuario('AD', '1234');

        $wrongPassword = $this->postJson('/api/auth/login', [
            'username' => 'AD',
            'password' => '9999',
        ]);
        $unknownUser = $this->postJson('/api/auth/login', [
            'username' => 'ZZ',
            'password' => '1234',
        ]);

        $wrongPassword->assertStatus(401);
        $unknownUser->assertStatus(401);
        $this->assertArrayNotHasKey('token', $wrongPassword->json());
        $this->assertSame($wrongPassword->json(), $unknownUser->json());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_login_lockout_returns_429_with_retry_after_even_for_correct_password(): void
    {
        $this->makeUsuario('AD', '1234');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', [
                'username' => 'AD',
                'password' => '9999',
            ])->assertStatus(401);
        }

        $response = $this->postJson('/api/auth/login', [
            'username' => 'AD',
            'password' => '1234',
        ]);

        $response->assertStatus(429);
        $this->assertTrue(
            $response->headers->has('Retry-After'),
            'Expected Retry-After header on lockout response'
        );
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_me_returns_authenticated_user_without_password_hash(): void
    {
        $usuario = $this->makeUsuario('AD', '1234');
        Sanctum::actingAs($usuario, ['*'], 'staff');

        $response = $this->getJson('/api/auth/me');

        $response->assertStatus(200);
        $response->assertJsonStructure(['id', 'username', 'rol_id', 'sede_id']);
        $response->assertJsonPath('id', $usuario->id);
        $response->assertJsonPath('username', 'AD');
        $this->assertArrayNotHasKey('password_hash', $response->json());
    }

    public function test_me_without_token_returns_401(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_logout_revokes_only_the_requesting_token(): void
    {
        $usuario = $this->makeUsuario('AD', '1234');
        $tokenA = $usuario->createToken('staff')->plainTextToken;
        $tokenB = $usuario->createToken('staff')->plainTextToken;

        $this->withToken($tokenA)->postJson('/api/auth/logout')->assertStatus(204);
        $this->assertDatabaseCount('personal_access_tokens', 1);

        // Simulate per-request guard isolation: this test makes several requests in one process.
        $this->app['auth']->forgetGuards();

        $this->withToken($tokenA)->getJson('/api/auth/me')->assertStatus(401);
        $this->app['auth']->forgetGuards();
        $this->withToken($tokenB)->getJson('/api/auth/me')->assertStatus(200);
    }

    public function test_logout_without_token_returns_401(): void
    {
        $this->postJson('/api/auth/logout')->assertStatus(401);
    }

    public function test_expired_token_is_rejected(): void
    {
        $usuario = $this->makeUsuario('AD', '1234');
        $token = $usuario->createToken('staff', ['*'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }
}
