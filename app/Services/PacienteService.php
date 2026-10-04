<?php

namespace App\Services;

use App\Models\Aptitud;
use App\Models\Paciente;
use Illuminate\Pagination\LengthAwarePaginator;

class PacienteService
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 50;

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

    /**
     * Paginated patient list for mobile prefetch.
     *
     * Supported filters:
     * - search: matches dni OR nombre OR apellido (case-insensitive LIKE).
     * - per_page: page size, default 15, capped at 50.
     *
     * Ordered by apellido then nombre so the list stays stable across pages.
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Paciente::query()
            ->with(['aptitud', 'activeDeferral'])
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $term = '%'.trim((string) $filters['search']).'%';

                $query->where(function ($query) use ($term) {
                    $query->where('dni', 'like', $term)
                        ->orWhere('nombre', 'like', $term)
                        ->orWhere('apellido', 'like', $term);
                });
            })
            ->orderBy('apellido')
            ->orderBy('nombre')
            ->paginate($this->perPage($filters['per_page'] ?? null));
    }

    private function perPage(mixed $requested): int
    {
        if (! is_numeric($requested)) {
            return self::DEFAULT_PER_PAGE;
        }

        return max(1, min((int) $requested, self::MAX_PER_PAGE));
    }

    public function update(Paciente $paciente, array $data): Paciente
    {
        // $data ya viene filtrado por UpdatePacienteRequest::validated()
        // solo contiene los campos presentes (sometimes)
        $paciente->update($data);

        return $paciente->fresh();
    }
}
