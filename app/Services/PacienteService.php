<?php

namespace App\Services;

use App\Models\Aptitud;
use App\Models\Paciente;
use App\Support\Paginacion;
use Illuminate\Pagination\LengthAwarePaginator;

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

    /**
     * Paginated patient list for mobile prefetch.
     *
     * Supported filters:
     * - search: matches dni, nombre or apellido, case- and accent-insensitive.
     * - per_page: page size, default 15, capped at 50.
     *
     * Ordered by apellido then nombre so the list stays stable across pages.
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Paciente::query()
            ->with(['aptitud', 'activeDeferral'])
            ->withExists('observaciones')
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $term = '%'.Paciente::normalizarBusqueda((string) $filters['search']).'%';

                $query->where('busqueda', 'like', $term);
            })
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->paginate(Paginacion::perPage($filters['per_page'] ?? null));
    }

    public function update(Paciente $paciente, array $data): Paciente
    {
        // $data ya viene filtrado por UpdatePacienteRequest::validated()
        // solo contiene los campos presentes (sometimes)
        $paciente->update($data);

        return $paciente->fresh();
    }
}
