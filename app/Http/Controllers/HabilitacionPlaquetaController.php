<?php

namespace App\Http\Controllers;

use App\Http\Requests\HabilitacionPlaqueta\StoreHabilitacionPlaquetaRequest;
use App\Models\Paciente;
use App\Services\HabilitacionPlaquetaService;
use Illuminate\Http\JsonResponse;

class HabilitacionPlaquetaController
{
    public function __construct(
        private HabilitacionPlaquetaService $habilitacionPlaquetaService
    ) {}

    public function index(string $dni): JsonResponse
    {
        $paciente = Paciente::where('dni', $dni)->firstOrFail();

        return response()->json($this->habilitacionPlaquetaService->list($paciente));
    }

    public function store(string $dni, StoreHabilitacionPlaquetaRequest $request): JsonResponse
    {
        $paciente = Paciente::where('dni', $dni)->firstOrFail();

        $habilitacion = $this->habilitacionPlaquetaService->create($paciente, $request->validated());

        return response()->json($habilitacion, 201);
    }

    public function destroy(string $dni): JsonResponse
    {
        $paciente = Paciente::where('dni', $dni)->firstOrFail();

        $habilitacion = $paciente->habilitacionesPlaquetas()
            ->vigente(now()->toDateString())
            ->firstOrFail();

        $this->habilitacionPlaquetaService->close($habilitacion);

        return response()->json(null, 204);
    }
}
