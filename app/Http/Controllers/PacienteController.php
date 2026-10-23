<?php

namespace App\Http\Controllers;

use App\Http\Requests\Paciente\IndexPacienteRequest;
use App\Http\Requests\Paciente\StorePacienteRequest;
use App\Http\Requests\Paciente\UpdatePacienteRequest;
use App\Http\Resources\PacienteResource;
use App\Models\Paciente;
use App\Services\PacienteService;

class PacienteController
{
    public function __construct(
        private PacienteService $pacienteService
    ) {}

    public function index(IndexPacienteRequest $request)
    {
        $pacientes = $this->pacienteService->list($request->validated());

        // Keep the repo's `response()->json(...)` style while preserving the
        // pagination envelope (data/meta/links); JSON-encoding a resource
        // collection directly drops the metadata.
        return response()->json(PacienteResource::collection($pacientes)->response($request)->getData(true));
    }

    public function show(Paciente $paciente)
    {
        $raw = $paciente->toArray();

        // Additive: keep the raw fields clients already read (aptitud_id, ...)
        // and add aptitud/activeDeferral in the same shape as the list.
        $paciente->load(['aptitud', 'activeDeferral'])->loadExists('observaciones');
        $resource = (new PacienteResource($paciente))->resolve();

        return response()->json([...$raw, ...$resource]);
    }

    public function store(StorePacienteRequest $request)
    {
        $paciente = $this->pacienteService->create($request->validated());

        return response()->json($paciente, 201);
    }

    public function update(Paciente $paciente, UpdatePacienteRequest $request)
    {
        $paciente = $this->pacienteService->update($paciente, $request->validated());

        return response()->json($paciente);
    }
}
