<?php

namespace App\Http\Controllers;

use App\Http\Requests\Observacion\StoreObservacionRequest;
use App\Models\Observacion;
use App\Models\Paciente;
use App\Services\ObservacionService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

        $this->observacionService->delete($this->observacionService->latestFor($paciente));

        return response()->json(null, 204);
    }

    /** Remove one observation by id. */
    public function destroyById(Observacion $observacion): JsonResponse
    {
        $this->observacionService->delete($observacion);

        return response()->json(null, 204);
    }

    /**
     * Remove one observation of the given patient; 404 when the observation
     * belongs to someone else.
     */
    public function destroyForPaciente(Paciente $paciente, Observacion $observacion): JsonResponse
    {
        if ((int) $observacion->paciente_id !== (int) $paciente->id) {
            throw (new ModelNotFoundException)->setModel(Observacion::class, [$observacion->id]);
        }

        $this->observacionService->delete($observacion);

        return response()->json(null, 204);
    }
}
