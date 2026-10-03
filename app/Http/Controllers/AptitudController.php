<?php

namespace App\Http\Controllers;

use App\Http\Requests\Aptitud\UpdateAptitudRequest;
use App\Models\Paciente;
use App\Services\AptitudService;
use Illuminate\Http\JsonResponse;

class AptitudController
{
    public function __construct(
        private AptitudService $aptitudService
    ) {}

    public function update(string $dni, UpdateAptitudRequest $request): JsonResponse
    {
        $paciente = Paciente::where('dni', $dni)->firstOrFail();

        $paciente = $this->aptitudService->update($paciente, $request->validated());

        $paciente->load('aptitud');

        return response()->json($paciente);
    }
}
