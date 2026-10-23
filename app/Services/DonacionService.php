<?php

namespace App\Services;

use App\Models\Autoexclusion;
use App\Models\AutorizacionExtraordinaria;
use App\Models\Donacion;
use App\Models\HabilitacionPlaqueta;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Models\Turno;
use App\Rules\ClinicalSignRules;
use App\Rules\ComponentRules;
use App\Rules\DonationRules;
use App\Support\ForcedAuthor;
use App\Support\Hoy;
use App\Support\NumeroDonacion;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DonacionService
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 50;

    public function __construct(
        private DonationRules $donationRules,
        private ComponentRules $componentRules,
        private ClinicalSignRules $clinicalSignRules
    ) {}

    /**
     * @return array{donacion: ?Donacion, warnings: array, allowed: bool}
     */
    public function create(array $data): array
    {
        $paciente = Paciente::findOrFail($data['paciente_id']);
        $tipo = TipoDonacion::findOrFail($data['tipo_id']);
        $fecha = $data['fecha'] ?? Hoy::fecha();

        // Component-shape errors (empty / non-catalog / over-limit) are 422 and
        // preempt domain warnings, so validate before DonationRules runs.
        $componentes = $this->assertComponentes($data);

        // Clinical integrity errors and the apheresis prior-hemogram gate are
        // 422 and MUST preempt the override branch: `forzar` overrides sourced
        // warnings only, never these errors.
        $signs = $this->extractSigns($data);
        $clinical = $this->clinicalSignRules->check($signs, (bool) $tipo->es_aferesis);

        if ($clinical['errors'] !== []) {
            throw ValidationException::withMessages(['signos_clinicos' => $clinical['errors']]);
        }

        $result = $this->donationRules->check($paciente, $tipo, $fecha);
        $warnings = array_merge(
            $result['warnings'],
            $clinical['warnings'],
            $this->plateletWarnings($paciente, $tipo, $fecha),
        );
        $allowed = $warnings === [];

        // Si hay warnings y no se fuerza, no crear nada. Devolver warnings para 409.
        if (! $allowed && empty($data['forzar'])) {
            return [
                'donacion' => null,
                'warnings' => $warnings,
                'allowed' => false,
            ];
        }

        // Si hay warnings y se fuerza, crear autorización + donación en transacción
        return DB::transaction(function () use ($paciente, $tipo, $fecha, $warnings, $allowed, $data, $componentes, $signs) {
            if (! $allowed && ! empty($data['forzar'])) {
                // Rule codes plus the operator's justification.
                $motivo = trim($this->buildMotivo($warnings).' — '.($data['motivo'] ?? ''), ' —');

                // Fail-closed: only the authenticated staff operator can authorize.
                $usuarioId = ForcedAuthor::idOrFail();

                AutorizacionExtraordinaria::create([
                    'paciente_id' => $paciente->id,
                    'usuario_id' => $usuarioId,
                    'motivo' => $motivo,
                    'fecha' => now(),
                ]);
            }

            $sede = $this->resolveSede($data);
            $numero = NumeroDonacion::allocate($sede, (int) Carbon::parse($fecha)->year);

            // Cycle position: an explicit override (all three fields) wins;
            // otherwise the odometer advances from the patient's counters.
            $ciclo = $this->resolveCyclePosition($paciente, $data);

            $donacion = Donacion::create([
                'paciente_id' => $paciente->id,
                'sede_id' => $sede->id,
                'tipo_id' => $tipo->id,
                'fecha' => $fecha,
                'numero_donacion' => $numero,
                'ciclo' => $ciclo['ciclo'],
                'serie' => $ciclo['serie'],
                'donacion' => $ciclo['donacion'],
                'tipo_bolsa' => $data['tipo_bolsa'] ?? null,
                'anticoagulante' => $data['anticoagulante'] ?? null,
                'lote' => $data['lote'] ?? null,
                'tubuladura' => $data['tubuladura'] ?? null,
                'brazo' => $data['brazo'] ?? null,
                'dificultad' => $data['dificultad'] ?? null,
                // Attribution is never client-supplied.
                'operador_id' => auth('staff')->id(),
                'doble_etiqueta' => (bool) ($data['doble_etiqueta'] ?? false),
                // Clinical signs captured with the donation (nullable).
                ...$this->signColumns($signs),
            ]);

            if ($componentes !== null) {
                $donacion->componentes()->createMany($componentes);
            }

            // The donation attends the donor's pending turno of that day here.
            Turno::where('paciente_id', $paciente->id)
                ->where('sede_id', $sede->id)
                ->whereDate('fecha', $fecha)
                ->where('estado', 'PENDIENTE')
                ->update(['estado' => 'ATENDIDO']);

            // Re-sync the patient counters with the position just persisted, so
            // the next computed donation continues from here.
            $paciente->update([
                'ciclo_actual' => $ciclo['ciclo'],
                'serie_actual' => $ciclo['serie'],
                'donacion_actual' => $ciclo['donacion'],
            ]);

            return [
                'donacion' => $donacion,
                'warnings' => $warnings,
                'allowed' => true,
            ];
        });
    }

    /**
     * Paginated, filterable donation list for the desktop app.
     *
     * Supported filters:
     * - paciente_id: exact owner match.
     * - desde / hasta: inclusive `fecha` bounds (YYYY-MM-DD).
     * - search: matches `numero_donacion` (case-insensitive LIKE).
     * - per_page: page size, default 15, capped at 50.
     *
     * Ordered by `fecha` desc then `id` desc. Relations are eager-loaded so the
     * DonacionResource and the model's `descartada` / `presion_arterial`
     * accessors never trigger a query per row.
     */
    public function list(array $filters = []): LengthAwarePaginator
    {
        return Donacion::query()
            ->with(['paciente', 'sede', 'tipoDonacion', 'componentes.tipoDonacion', 'autoexclusion'])
            ->when(filled($filters['paciente_id'] ?? null), fn ($query) => $query->where('paciente_id', $filters['paciente_id']))
            ->when(filled($filters['desde'] ?? null), fn ($query) => $query->where('fecha', '>=', $filters['desde']))
            ->when(filled($filters['hasta'] ?? null), fn ($query) => $query->where('fecha', '<=', $filters['hasta']))
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters) {
                $query->where('numero_donacion', 'like', '%'.trim((string) $filters['search']).'%');
            })
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate($this->perPage($filters['per_page'] ?? null));
    }

    private function perPage(mixed $requested): int
    {
        if (! is_numeric($requested)) {
            return self::DEFAULT_PER_PAGE;
        }

        return max(1, min((int) $requested, self::MAX_PER_PAGE));
    }

    /**
     * Record a confidential self-exclusion and, transactionally, discard every
     * component of the donation. A duplicate record is an integrity error (422);
     * the UNIQUE(donacion_id) constraint is the concurrency backstop.
     */
    public function marcarAutoexclusion(Donacion $donacion, array $data): Autoexclusion
    {
        if ($donacion->autoexclusion()->exists()) {
            throw ValidationException::withMessages([
                'autoexclusion' => ['La donación ya tiene una autoexclusión registrada.'],
            ]);
        }

        try {
            return DB::transaction(function () use ($donacion, $data): Autoexclusion {
                /** @var Autoexclusion $autoexclusion */
                $autoexclusion = $donacion->autoexclusion()->create([
                    'motivo' => $data['motivo'] ?? null,
                    'created_by' => auth('staff')->id(),
                ]);

                // Discard every unit; disposal keeps the discard reason non-null.
                $donacion->componentes()->update([
                    'descartado' => true,
                    'motivo_descarte' => $data['motivo'] ?? 'Autoexclusión del donante',
                ]);

                return $autoexclusion;
            });
        } catch (QueryException) {
            throw ValidationException::withMessages([
                'autoexclusion' => ['La donación ya tiene una autoexclusión registrada.'],
            ]);
        }
    }

    /**
     * Resolve the donation's sede: explicit payload, then the operator's home
     * sede, then the database default (Sede Central).
     */
    private function resolveSede(array $data): Sede
    {
        if (! empty($data['sede_id'])) {
            return Sede::findOrFail($data['sede_id']);
        }

        $staffSedeId = auth('staff')->user()?->sede_id;

        if ($staffSedeId !== null) {
            return Sede::findOrFail($staffSedeId);
        }

        $defaultId = DB::table('sedes')->where('nombre', 'Sede Central')->value('id')
            ?? DB::table('sedes')->orderBy('id')->value('id');

        return Sede::findOrFail($defaultId);
    }

    /**
     * Resolve the donation's cycle position (ciclo / serie / donacion).
     *
     * An explicit override (all three fields sent, non-null) is respected as-is
     * and re-syncs the patient counters. Otherwise the donor's odometer advances
     * by one from the patient counters: donacion 1..N within the serie, serie
     * 1..M within the ciclo, overflowing the serie opens the next ciclo. A donor
     * with no counters yet starts at 1/1/1. Limits come from
     * config('ciclo_donacion.*').
     *
     * @param  array<string, mixed>  $data
     * @return array{ciclo: int, serie: int, donacion: int}
     */
    private function resolveCyclePosition(Paciente $paciente, array $data): array
    {
        $ciclo = $data['ciclo'] ?? null;
        $serie = $data['serie'] ?? null;
        $donacion = $data['donacion'] ?? null;

        if ($ciclo !== null && $serie !== null && $donacion !== null) {
            return ['ciclo' => (int) $ciclo, 'serie' => (int) $serie, 'donacion' => (int) $donacion];
        }

        $maxDonaciones = (int) config('ciclo_donacion.donaciones_por_serie');
        $maxSeries = (int) config('ciclo_donacion.series_por_ciclo');

        $nextCiclo = $paciente->ciclo_actual ?? 1;
        $nextSerie = $paciente->serie_actual ?? 1;
        $nextDonacion = ($paciente->donacion_actual ?? 0) + 1;

        if ($nextDonacion > $maxDonaciones) {
            $nextDonacion = 1;
            $nextSerie++;
        }

        if ($nextSerie > $maxSeries) {
            $nextSerie = 1;
            $nextCiclo++;
        }

        return ['ciclo' => $nextCiclo, 'serie' => $nextSerie, 'donacion' => $nextDonacion];
    }

    /**
     * Validate the optional component set and normalize it to persistence rows.
     *
     * The HTTP request always requires at least one component. The service
     * treats an ABSENT `componentes` key as the legacy/internal path (no rows
     * written), which keeps direct-service callers backward compatible.
     *
     * @return array<int, array{tipo_id: int, vencimiento: ?string, peso: ?float}>|null Rows, or null when the key is absent.
     *
     * @throws ValidationException when the set is empty, non-catalog or over-limit.
     */
    private function assertComponentes(array $data): ?array
    {
        if (! array_key_exists('componentes', $data)) {
            return null;
        }

        // Bare ints (legacy callers) and {tipo_id} objects both map to rows.
        $rows = array_map(
            fn ($componente): array => [
                'tipo_id' => (int) (is_array($componente) ? ($componente['tipo_id'] ?? 0) : $componente),
                'vencimiento' => is_array($componente) ? ($componente['vencimiento'] ?? null) : null,
                'peso' => is_array($componente) ? ($componente['peso'] ?? null) : null,
            ],
            array_values($data['componentes'] ?? [])
        );

        $ids = array_column($rows, 'tipo_id');
        $tipos = TipoDonacion::whereIn('id', $ids)->get()->keyBy('id');

        $codigos = [];
        $errors = [];

        foreach ($ids as $id) {
            if (! $tipos->has($id)) {
                $errors[] = 'Componente no existe en el catálogo.';

                continue;
            }

            $codigos[] = $tipos->get($id)->codigo;
        }

        $errors = array_merge($errors, $this->componentRules->check($codigos));

        if ($errors !== []) {
            throw ValidationException::withMessages(['componentes' => $errors]);
        }

        return $rows;
    }

    /**
     * Platelet product (`codigo=PLAQUETAS`) requires an active enable. This is
     * an overridable warning (409) that merges into the donation warning set so
     * `forzar` works unchanged. Non-platelet types are never gated.
     *
     * @return array<int, array{code: string, message: string}>
     */
    private function plateletWarnings(Paciente $paciente, TipoDonacion $tipo, string $fecha): array
    {
        if (strtoupper((string) $tipo->codigo) !== 'PLAQUETAS') {
            return [];
        }

        $habilitada = HabilitacionPlaqueta::where('paciente_id', $paciente->id)
            ->vigente($fecha)
            ->exists();

        if ($habilitada) {
            return [];
        }

        return [[
            'code' => 'PLAQUETAS_NO_HABILITADAS',
            'message' => 'PLAQUETAS: el donante no tiene habilitación vigente para aféresis de plaquetas.',
        ]];
    }

    private function buildMotivo(array $warnings): string
    {
        // Genera motivo a partir del code, no del message que se muestra al usuario
        $codes = array_column($warnings, 'code');

        if (empty($codes)) {
            return 'Autorización extraordinaria';
        }

        $map = [
            'BLOQUEO_DIFERIMIENTO' => 'Diferimiento activo',
            'INTERVALO_MINIMO' => 'Incumplimiento de intervalo mínimo',
            'LIMITE_ANUAL' => 'Supera límite anual',
            'LIMITE_PERIODO' => 'Supera límite del período',
            'HEMOGLOBINA_BAJA' => 'Hemoglobina baja',
            'PESO_BAJO' => 'Peso bajo',
            'PLAQUETAS_NO_HABILITADAS' => 'Plaquetas no habilitadas',
        ];

        $motivos = array_map(fn ($c) => $map[$c] ?? $c, $codes);

        return implode(' + ', $motivos);
    }

    /**
     * Pull only the clinical-sign keys out of the payload.
     *
     * Missing keys, null and empty string all mean "not captured"; the pure
     * rules object interprets absence itself.
     *
     * @return array<string, mixed>
     */
    private function extractSigns(array $data): array
    {
        $signs = [];

        foreach (ClinicalSignRules::SIGNS as $sign) {
            if (array_key_exists($sign, $data)) {
                $signs[$sign] = $data[$sign];
            }
        }

        return $signs;
    }

    /**
     * Normalise captured signs into insertable columns, defaulting each to null.
     *
     * @param  array<string, mixed>  $signs
     * @return array<string, mixed>
     */
    private function signColumns(array $signs): array
    {
        $columns = [];

        foreach (ClinicalSignRules::SIGNS as $sign) {
            $columns[$sign] = $signs[$sign] ?? null;
        }

        return $columns;
    }
}
