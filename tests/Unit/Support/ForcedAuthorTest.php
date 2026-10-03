<?php

namespace Tests\Unit\Support;

use App\Exceptions\ForcedOperationRequiresAuthenticationException;
use App\Models\Rol;
use App\Models\Usuario;
use App\Support\ForcedAuthor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ForcedAuthorTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_the_authenticated_staff_id(): void
    {
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);
        $usuario = Usuario::create([
            'username' => 'AD',
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);

        Sanctum::actingAs($usuario, ['*'], 'staff');

        $this->assertSame($usuario->id, ForcedAuthor::idOrFail());
    }

    public function test_throws_when_no_staff_is_authenticated(): void
    {
        // A user existing in the database must not become a fallback author.
        $rol = Rol::firstOrCreate(['nombre' => 'ADMIN']);
        Usuario::create([
            'username' => 'AD',
            'password_hash' => Hash::make('1234'),
            'rol_id' => $rol->id,
        ]);

        $this->expectException(ForcedOperationRequiresAuthenticationException::class);

        ForcedAuthor::idOrFail();
    }
}
