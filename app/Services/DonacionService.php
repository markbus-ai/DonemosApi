<?php

namespace App\Services;

use App\Models\AutorizacionExtraordinaria;
use App\Models\Donacion;
use App\Models\Paciente;
use App\Models\Sede;
use App\Models\TipoDonacion;
use App\Rules\ComponentRules;
use App\Rules\DonationRules;
use App\Support\ForcedAuthor;
use App\Support\NumeroDonacion;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DonacionService
{
    public function __construct(
        private DonationRules $donationRules,
        private ComponentRules $componentRules
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

        $result = $this->donationRules->check($paciente, $tipo, $fecha);
        $warnings = $result['warnings'];
        $allowed = $result['allowed'];

        // Si hay warnings y no se fuerza, no crear nada. Devolver warnings para 409.
        if (! $allowed && empty($data['forzar'])) {
            return [
                'donacion' => null,
                'warnings' => $warnings,
                'allowed' => false,
            ];
        }

        // Si hay warnings y se fuerza, crear autorización + donación en transacción
        return DB::transaction(function () use ($paciente, $tipo, $fecha, $warnings, $allowed, $data, $componentes) {
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
     * @return array<int, array{tipo_id: int}>|null Rows, or null when the key is absent.
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
        ];

        $motivos = array_map(fn ($c) => $map[$c] ?? $c, $codes);

        return implode(' + ', $motivos);
    }
}
