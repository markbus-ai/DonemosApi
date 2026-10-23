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

    public function index(Turno $turno): JsonResponse
    {
        return response()->json($this->recordatorioService->list($turno));
    }

    public function store(Turno $turno): JsonResponse
    {
        return response()->json($this->recordatorioService->create($turno), 201);
    }
}
