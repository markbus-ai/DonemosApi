<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UsuarioResource;
use App\Models\Usuario;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        $usuario = Usuario::where('username', $credentials['username'])->first();

        // Uniform rejection body: never reveal whether the username exists.
        if (! $usuario || ! Hash::check($credentials['password'], $usuario->getAuthPassword())) {
            return response()->json(['message' => 'Invalid credentials.'], 401);
        }

        $token = $usuario->createToken('staff')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => new UsuarioResource($usuario),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(new UsuarioResource($request->user()));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(null, 204);
    }
}
