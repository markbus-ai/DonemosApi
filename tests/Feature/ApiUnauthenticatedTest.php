<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * An unauthenticated API request must always answer JSON 401. It used to blow
 * up with a 500 stack trace because the auth middleware tried to redirect to a
 * `login` web route that does not exist (the app is API-only).
 */
class ApiUnauthenticatedTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_token_without_json_accept_returns_401_not_500(): void
    {
        $response = $this->call('GET', '/api/turnos', [], [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer 999|invalidtoken',
        ]);

        $response->assertStatus(401);
        $this->assertSame('Unauthenticated.', $response->json('message'));
    }

    public function test_missing_token_without_json_accept_returns_401(): void
    {
        $response = $this->call('GET', '/api/turnos');

        $response->assertStatus(401);
        $this->assertSame('Unauthenticated.', $response->json('message'));
    }
}
