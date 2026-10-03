<?php

namespace App\Services;

use App\Models\Aptitud;
use App\Models\Paciente;

class PacienteService
{
    public function create(array $data): Paciente
    {
        $aptitud = Aptitud::where('tipo', 'APTO')->firstOrFail();

        return Paciente::create([
            'dni' => $data['dni'],
            'nombre' => $data['nombre'],
            'apellido' => $data['apellido'],
            'telefono' => $data['telefono'],
            'sexo' => $data['sexo'] ?? null,
            'altura' => $data['altura'] ?? null,
            'aptitud_id' => $aptitud->id,
        ]);
    }

    public function findByDni(string $dni): Paciente
    {
        return Paciente::where('dni', $dni)->firstOrFail();
    }

    public function update(Paciente $paciente, array $data): Paciente
    {
        // $data ya viene filtrado por UpdatePacienteRequest::validated()
        // solo contiene los campos presentes (sometimes)
        $paciente->update($data);

        return $paciente->fresh();
    }
}
