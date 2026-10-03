<?php

namespace App\Rules;

/**
 * ClinicalSignRules - pure domain object for pre-donation clinical signs.
 *
 * Thresholds come from config/clinical_signs.php:
 *  - `sourced`: verifiable thresholds that gate as OVERRIDABLE warnings (409).
 *  - `capture_only`: recorded fields with unverified thresholds; never gated.
 *
 * Integrity problems (negative or non-numeric values, diastolic above systolic)
 * and the apheresis prior-hemogram precondition are NON-OVERRIDABLE errors
 * (422). `forzar` is handled by the caller: it may override warnings, never
 * errors.
 *
 * This class never persists or reads the database.
 *
 * Returns: ['errors' => [string], 'warnings' => [['code', 'message']]]
 */
final class ClinicalSignRules
{
    /** Every sign column recognised on `donaciones`. */
    public const SIGNS = [
        'hemoglobina',
        'hematocrito',
        'plaquetas',
        'presion_sistolica',
        'presion_diastolica',
        'frecuencia_cardiaca',
        'peso_donante',
    ];

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param  array<string, mixed>|null  $config  Defaults to config('clinical_signs').
     */
    public function __construct(?array $config = null)
    {
        $this->config = $config ?? config('clinical_signs', []);
    }

    /**
     * @param  array<string, mixed>  $signs  Parsed signs; missing or null means not captured.
     * @return array{errors: array<int, string>, warnings: array<int, array{code: string, message: string}>}
     */
    public function check(array $signs, bool $esAferesis): array
    {
        [$errors, $numeric] = $this->validateIntegrity($signs);

        if (isset($numeric['presion_sistolica'], $numeric['presion_diastolica'])
            && $numeric['presion_diastolica'] > $numeric['presion_sistolica']) {
            $errors[] = 'La presión diastólica no puede superar la presión sistólica.';
        }

        if ($esAferesis && (! isset($numeric['plaquetas']) || ! isset($numeric['hematocrito']))) {
            $errors[] = 'Las donaciones por aféresis requieren recuento de plaquetas y hematocrito.';
        }

        return [
            'errors' => $errors,
            'warnings' => $this->sourcedWarnings($numeric),
        ];
    }

    /**
     * Normalise provided signs and flag negative or non-numeric values.
     *
     * @param  array<string, mixed>  $signs
     * @return array{0: array<int, string>, 1: array<string, float>}
     */
    private function validateIntegrity(array $signs): array
    {
        $errors = [];
        $numeric = [];

        foreach (self::SIGNS as $sign) {
            if (! array_key_exists($sign, $signs) || $signs[$sign] === null || $signs[$sign] === '') {
                continue;
            }

            $value = $signs[$sign];

            if (! is_numeric($value)) {
                $errors[] = "El valor de {$sign} debe ser numérico.";

                continue;
            }

            if ((float) $value < 0) {
                $errors[] = "El valor de {$sign} no puede ser negativo.";

                continue;
            }

            $numeric[$sign] = (float) $value;
        }

        return [$errors, $numeric];
    }

    /**
     * Only `sourced` fields gate; capture-only fields are never evaluated.
     *
     * @param  array<string, float>  $numeric
     * @return array<int, array{code: string, message: string}>
     */
    private function sourcedWarnings(array $numeric): array
    {
        $warnings = [];

        foreach (($this->config['sourced'] ?? []) as $sign => $definition) {
            if (! isset($numeric[$sign])) {
                continue;
            }

            $min = (float) ($definition['min'] ?? 0);

            if ($numeric[$sign] >= $min) {
                continue;
            }

            $warnings[] = [
                'code' => (string) ($definition['code'] ?? strtoupper((string) $sign)),
                'message' => sprintf(
                    '%s (%s) por debajo del mínimo: %s %s.',
                    $sign,
                    $numeric[$sign],
                    $min,
                    (string) ($definition['unit'] ?? ''),
                ),
            ];
        }

        return $warnings;
    }
}
