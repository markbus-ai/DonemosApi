<?php

namespace App\Http\Controllers;

use App\Http\Requests\Donacion\IndexDonacionRequest;
use App\Http\Requests\Donacion\StoreDonacionRequest;
use App\Http\Resources\DonacionResource;
use App\Services\DonacionService;

class DonacionController
{
    public function __construct(
        private DonacionService $donacionService
    ) {}

    public function index(IndexDonacionRequest $request)
    {
        $donaciones = $this->donacionService->list($request->validated());

        // Keep the repo's `response()->json(...)` style while preserving the
        // pagination envelope (data/meta/links); JSON-encoding a resource
        // collection directly drops the metadata.
        return response()->json(DonacionResource::collection($donaciones)->response($request)->getData(true));
    }

    /** Rule conflicts surface as RuleWarningsException (409). */
    public function store(StoreDonacionRequest $request)
    {
        $donacion = $this->donacionService->create($request->validated());

        return response()->json($donacion->load('componentes'), 201);
    }
}
