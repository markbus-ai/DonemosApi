<?php

namespace App\Http\Controllers;

use App\Http\Requests\Donacion\StoreDonacionRequest;
use App\Services\DonacionService;

class DonacionController
{
    public function __construct(
        private DonacionService $donacionService
    ) {}

    public function index()
    {
        // TODO: listado con filtros si se requiere. Por ahora retorna todo o por paciente.
        return response()->json([]);
    }

    public function store(StoreDonacionRequest $request)
    {
        $result = $this->donacionService->create($request->validated());

        if (!$result['allowed'] && $result['donacion'] === null) {
            return response()->json([
                'warnings' => $result['warnings'],
            ], 409);
        }

        return response()->json($result['donacion'], 201);
    }
}
