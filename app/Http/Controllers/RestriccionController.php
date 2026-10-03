<?php

namespace App\Http\Controllers;

use App\Http\Requests\Restriccion\StoreRestriccionRequest;
use App\Models\Paciente;
use App\Services\RestriccionService;
use Illuminate\Http\JsonResponse;

class RestriccionController
{
    public function __construct(
        private RestriccionService $restriccionService
    ) {}

    public function store(string $dni, StoreRestriccionRequest $request): JsonResponse
    {
        $paciente = Paciente::where('dni', $dni)->firstOrFail();

        // A new deferral closes the prior active row; it is never rejected as a duplicate.
        $restriccion = $this->restriccionService->create($paciente, $request->validated());

        return response()->json($restriccion, 201);
    }

    public function destroy(string $dni): JsonResponse
    {
        $paciente = Paciente::where('dni', $dni)->firstOrFail();

        $restriccion = $paciente->activeDeferral()->firstOrFail();

        $this->restriccionService->close($restriccion);

        return response()->json(null, 204);
    }
}
