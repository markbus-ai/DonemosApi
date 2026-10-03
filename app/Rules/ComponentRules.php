<?php

namespace App\Rules;

/**
 * ComponentRules - pure domain object for donation component sets.
 *
 * Quantity is represented by the row count per catalog code. At most one
 * SANGRE, one PLASMA and three PLAQUETAS bags are accepted per donation.
 * Empty and unknown codes are rejected. This class never persists or reads
 * the database.
 */
final class ComponentRules
{
    /** Per-code maximum number of bags allowed in one donation. */
    public const MAXIMA = [
        'SANGRE' => 1,
        'PLASMA' => 1,
        'PLAQUETAS' => 3,
    ];

    /**
     * @param  array<int, string>  $codigos  Catalog codes; duplicates are the quantity.
     * @return array<int, string> Validation error messages; empty when the set is valid.
     */
    public function check(array $codigos): array
    {
        if ($codigos === []) {
            return ['Se requiere al menos un componente.'];
        }

        $errors = [];

        foreach (array_count_values($codigos) as $codigo => $count) {
            if (! array_key_exists($codigo, self::MAXIMA)) {
                $errors[] = "Componente no reconocido en el catálogo: {$codigo}.";

                continue;
            }

            $maximo = self::MAXIMA[$codigo];

            if ($count > $maximo) {
                $errors[] = "{$codigo}: máximo {$maximo} por donación.";
            }
        }

        return $errors;
    }
}
