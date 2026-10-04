<?php

namespace App\Http\Controllers;

use App\Models\Turno;
use App\Services\RecordatorioService;
use Illuminate\Http\JsonResponse;

class RecordatorioController
{
    public function __construct(
        private RecordatorioService $recordatorioService
    ) {}

    public function index(string $id): JsonResponse
    {
        $turno = Turno::findOrFail($id);

        return response()->json($this->recordatorioService->list($turno));
    }

    public function store(string $id): JsonResponse
    {
        $turno = Turno::findOrFail($id);

        $record = $this->recordatorioService->create($turno);

        return response()->json($record, 201);
    }
}
