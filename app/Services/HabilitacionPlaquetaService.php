<?php

namespace App\Services;

use App\Models\HabilitacionPlaqueta;
use App\Models\Paciente;
use App\Rules\PlateletEnableRules;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class HabilitacionPlaquetaService
{
    public function __construct(
        private PlateletEnableRules $rules
    ) {}

    /**
     * Enable platelets for a patient. Any prior active window is closed on the
     * new `desde` (never deleted) and a new window is appended. The resolved
     * `hasta` never shortens an unexpired prior expiry.
     */
    public function create(Paciente $paciente, array $data): HabilitacionPlaqueta
    {
        return DB::transaction(function () use ($paciente, $data) {
            $desde = Carbon::parse($data['desde'] ?? now())->startOfDay()->toDateString();

            $prior = $paciente->habilitacionesPlaquetas()
                ->vigente($desde)
                ->orderByDesc('hasta')
                ->first();

            // Capture the prior expiry BEFORE closing the row so an unexpired
            // `hasta` is never shortened by the re-enable.
            $priorHasta = $prior?->hasta;

            if ($prior !== null) {
                $prior->update(['hasta' => $desde]);
            }

            $hasta = $this->rules->resolveHasta(
                $data['hasta'] ?? null,
                $desde,
                $priorHasta,
            );

            return $paciente->habilitacionesPlaquetas()->create([
                'desde' => $desde,
                'hasta' => $hasta,
                'created_by' => auth('staff')->id(),
            ]);
        });
    }

    public function list(Paciente $paciente): Collection
    {
        return $paciente->habilitacionesPlaquetas()->orderByDesc('desde')->get();
    }

    /**
     * Disable platelets today by closing the active window (never deleting it).
     */
    public function close(HabilitacionPlaqueta $habilitacion): void
    {
        $habilitacion->update(['hasta' => now()->toDateString()]);
    }
}
