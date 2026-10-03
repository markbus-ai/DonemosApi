<?php

namespace App\Services;

use App\Models\Autoexclusion;
use App\Models\AutorizacionExtraordinaria;
use App\Models\Donacion;
use App\Models\HabilitacionPlaqueta;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Rules\ClinicalSignRules;
use App\Rules\ComponentRules;
use App\Rules\DonationRules;
use App\Support\ForcedAuthor;
use App\Support\NumeroDonacion;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DonacionService
{
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
        $fecha = $data['fecha'] ?? now()->toDateString();

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
                // Motivo autogenerado a partir del code, no viene del cliente
                $motivo = $this->buildMotivo($warnings);

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

            $donacion = Donacion::create([
                'paciente_id' => $paciente->id,
                'sede_id' => $sede->id,
                'tipo_id' => $tipo->id,
                'fecha' => $fecha,
                'numero_donacion' => $numero,
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

            return [
                'donacion' => $donacion,
                'warnings' => $warnings,
                'allowed' => true,
            ];
        });
    }

    public function list(int $pacienteId): Collection
    {
        return Donacion::where('paciente_id', $pacienteId)->orderByDesc('fecha')->get();
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
