<?php

namespace App\Http\Controllers;

use App\Http\Requests\Paciente\StorePacienteRequest;
use App\Http\Requests\Paciente\UpdatePacienteRequest;
use App\Http\Resources\PacienteResource;
use App\Services\PacienteService;
use Illuminate\Http\Request;

class PacienteController
{
    public function __construct(
        private PacienteService $pacienteService
    ) {}

    public function index(Request $request)
    {
        $pacientes = $this->pacienteService->list($request->only(['search', 'per_page']));

        // Keep the repo's `response()->json(...)` style while preserving the
        // pagination envelope (data/meta/links); JSON-encoding a resource
        // collection directly drops the metadata.
        return response()->json(PacienteResource::collection($pacientes)->response($request)->getData(true));
    }

    public function show(string $dni)
    {
        $paciente = $this->pacienteService->findByDni($dni);

        return response()->json($paciente);
    }

    public function store(StorePacienteRequest $request)
    {
        $paciente = $this->pacienteService->create($request->validated());

        return response()->json($paciente, 201);
    }

    public function update(string $dni, UpdatePacienteRequest $request)
    {
        $paciente = $this->pacienteService->findByDni($dni);
        $paciente = $this->pacienteService->update($paciente, $request->validated());

        return response()->json($paciente);
    }
}
