<?php

namespace App\Http\Controllers;

use App\Http\Requests\Donacion\StoreAutoexclusionRequest;
use App\Http\Resources\AutoexclusionResource;
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
    public function store(Donacion $donacion, StoreAutoexclusionRequest $request): JsonResponse
    {
        $autoexclusion = $this->donacionService->marcarAutoexclusion($donacion, $request->validated());

        return response()->json(new AutoexclusionResource($autoexclusion), 201);
    }
}
