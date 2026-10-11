<?php

namespace App\Http\Controllers;

use App\Http\Requests\Donacion\StoreDonacionRequest;
use App\Http\Resources\DonacionResource;
use App\Services\DonacionService;
use Illuminate\Http\Request;

class DonacionController
{
    public function __construct(
        private DonacionService $donacionService
    ) {}

    public function index(Request $request)
    {
        $donaciones = $this->donacionService->list(
            $request->only(['paciente_id', 'desde', 'hasta', 'search', 'per_page'])
        );

        // Keep the repo's `response()->json(...)` style while preserving the
        // pagination envelope (data/meta/links); JSON-encoding a resource
        // collection directly drops the metadata.
        return response()->json(DonacionResource::collection($donaciones)->response($request)->getData(true));
    }

    public function store(StoreDonacionRequest $request)
    {
        $result = $this->donacionService->create($request->validated());

        if (! $result['allowed'] && $result['donacion'] === null) {
            return response()->json([
                'warnings' => $result['warnings'],
            ], 409);
        }

        return response()->json($result['donacion']->load('componentes'), 201);
    }
}
