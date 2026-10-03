<?php

namespace App\Http\Controllers;

use App\Http\Requests\Restriccion\StoreRestriccionRequest;
use App\Models\Paciente;
use App\Services\RestriccionService;
use DomainException;
use Illuminate\Http\JsonResponse;

class RestriccionController
{
    public function __construct(
        private RestriccionService $restriccionService
    ) {}

    public function store(string $dni, StoreRestriccionRequest $request): JsonResponse
    {
        try {
            $paciente = Paciente::where('dni', $dni)->firstOrFail();

            $restriccion = $this->restriccionService->create($paciente, $request->validated());

            return response()->json($restriccion, 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }
    }

    public function destroy(string $dni): JsonResponse
    {
        $paciente = Paciente::where('dni', $dni)->firstOrFail();

        $restriccion = $paciente->restriccion()->firstOrFail();

        $this->restriccionService->delete($restriccion);

        return response()->json(null, 204);
    }
}
