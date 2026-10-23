<?php

namespace App\Http\Controllers;

use App\Http\Requests\Turno\IndexTurnoRequest;
use App\Http\Requests\Turno\StoreTurnoRequest;
use App\Http\Requests\Turno\UpdateTurnoRequest;
use App\Http\Resources\TurnoResource;
use App\Models\Turno;
use App\Services\TurnoService;

class TurnoController
{
    public function __construct(
        private TurnoService $turnoService
    ) {}

    public function index(IndexTurnoRequest $request)
    {
        $turnos = $this->turnoService->list($request->validated());

        return response()->json(TurnoResource::collection($turnos));
    }

    /** Rule conflicts surface as RuleWarningsException (409). */
    public function store(StoreTurnoRequest $request)
    {
        $turno = $this->turnoService->create($request->validated());

        $turno->load(['paciente', 'sede', 'tipoDonacion']);

        return response()->json(new TurnoResource($turno), 201);
    }

    public function update(Turno $turno, UpdateTurnoRequest $request)
    {
        $turno = $this->turnoService->update($turno, $request->validated());

        $turno->load(['paciente', 'sede', 'tipoDonacion']);

        return response()->json(new TurnoResource($turno));
    }
}
