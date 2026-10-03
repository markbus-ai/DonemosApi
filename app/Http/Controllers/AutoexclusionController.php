<?php

namespace App\Http\Controllers;

use App\Http\Requests\Donacion\StoreAutoexclusionRequest;
use App\Models\Donacion;
use App\Services\DonacionService;
use Illuminate\Http\JsonResponse;

class AutoexclusionController
{
    public function __construct(
        private DonacionService $donacionService
    ) {}

    /**
     * Record a self-exclusion by donation number. The response intentionally
     * omits any patient identity.
     */
    public function store(string $numero, StoreAutoexclusionRequest $request): JsonResponse
    {
        $donacion = Donacion::where('numero_donacion', $numero)->firstOrFail();

        $autoexclusion = $this->donacionService->marcarAutoexclusion($donacion, $request->validated());

        return response()->json([
            'id' => $autoexclusion->id,
            'donacion_id' => $autoexclusion->donacion_id,
            'motivo' => $autoexclusion->motivo,
            'created_by' => $autoexclusion->created_by,
        ], 201);
    }
}
