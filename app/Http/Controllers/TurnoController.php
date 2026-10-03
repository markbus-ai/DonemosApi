<?php

namespace App\Http\Controllers;

use App\Http\Requests\Turno\StoreTurnoRequest;
use App\Http\Requests\Turno\UpdateTurnoRequest;
use App\Models\Turno;
use App\Services\TurnoService;
use Illuminate\Http\Request;

class TurnoController
{
    public function __construct(
        private TurnoService $turnoService
    ) {}

    public function index(Request $request)
    {
        $turnos = $this->turnoService->list($request->only(['paciente_id', 'fecha']));

        return response()->json($turnos);
    }

    public function store(StoreTurnoRequest $request)
    {
        $result = $this->turnoService->create($request->validated());

        // Si el Service detectó violación sin forzar, devolver 409 con warning
        if (is_array($result) && ($result['warning'] ?? false)) {
            return response()->json($result, 409);
        }

        return response()->json($result, 201);
    }

    public function update(string $id, UpdateTurnoRequest $request)
    {
        $turno = Turno::findOrFail($id);
        $turno = $this->turnoService->update($turno, $request->validated());

        return response()->json($turno);
    }
}
