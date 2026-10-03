<?php

namespace App\Http\Controllers;

use App\Http\Requests\Observacion\StoreObservacionRequest;
use App\Models\Paciente;
use App\Services\ObservacionService;
use Illuminate\Http\JsonResponse;

class ObservacionController
{
    public function __construct(
        private ObservacionService $observacionService
    ) {}

    public function store(string $dni, StoreObservacionRequest $request): JsonResponse
    {
        $paciente = Paciente::where('dni', $dni)->firstOrFail();

        $observacion = $this->observacionService->create($paciente, $request->validated());

        return response()->json($observacion, 201);
    }

    public function destroy(string $dni): JsonResponse
    {
        $paciente = Paciente::where('dni', $dni)->firstOrFail();

        $observacion = $paciente->observaciones()->firstOrFail();

        $this->observacionService->delete($observacion);

        return response()->json(null, 204);
    }
}
