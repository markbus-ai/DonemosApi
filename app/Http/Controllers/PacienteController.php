<?php

namespace App\Http\Controllers;

use App\Http\Requests\Paciente\StorePacienteRequest;
use App\Http\Requests\Paciente\UpdatePacienteRequest;
use App\Services\PacienteService;

class PacienteController
{
    public function __construct(
        private PacienteService $pacienteService
    ) {}

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
