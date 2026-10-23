<?php

namespace App\Http\Controllers;

use App\Http\Requests\HabilitacionPlaqueta\StoreHabilitacionPlaquetaRequest;
use App\Models\Paciente;
use App\Services\HabilitacionPlaquetaService;
use App\Support\Hoy;
use Illuminate\Http\JsonResponse;

class HabilitacionPlaquetaController
{
    public function __construct(
        private HabilitacionPlaquetaService $habilitacionPlaquetaService
    ) {}

    public function index(Paciente $paciente): JsonResponse
    {
        return response()->json($this->habilitacionPlaquetaService->list($paciente));
    }

    public function store(Paciente $paciente, StoreHabilitacionPlaquetaRequest $request): JsonResponse
    {
        $habilitacion = $this->habilitacionPlaquetaService->create($paciente, $request->validated());

        return response()->json($habilitacion, 201);
    }

    public function destroy(Paciente $paciente): JsonResponse
    {
        $habilitacion = $paciente->habilitacionesPlaquetas()
            ->vigente(Hoy::fecha())
            ->firstOrFail();

        $this->habilitacionPlaquetaService->close($habilitacion);

        return response()->json(null, 204);
    }
}
