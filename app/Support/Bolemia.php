<?php

namespace App\Support;

/**
 * Blood-volume (bolemia) estimation with the Nadler formula.
 *
 * Reference: Nadler SB, Hidalgo JH, Bloch T. "Prediction of blood volume in
 * normal human adults." Surgery. 1962;51(2):224-232.
 *
 * Male:   BV(L) = 0.3669*H^3 + 0.03219*W + 0.6041
 * Female: BV(L) = 0.3561*H^3 + 0.03308*W + 0.1833
 *
 * H is height in metres, W is body weight in kilograms. The result returned
 * here is converted to MILLILITRES (mL) for consistency with donation volumes.
 *
 * Pure and database-free: returns null unless sex, height and weight are all
 * present and the sex is a known M/F value. Never guesses.
 */
final class Bolemia
{
    public const SEX_MALE = 'M';

    public const SEX_FEMALE = 'F';

    /**
     * Total blood volume in millilitres.
     *
     * @param  string|null  $sexo  'M' or 'F' (case-insensitive); anything else is unknown.
     * @param  float|null  $alturaCm  Height in centimetres.
     * @param  float|null  $pesoKg  Body weight in kilograms.
     */
    public static function compute(?string $sexo, ?float $alturaCm, ?float $pesoKg): ?float
    {
        if ($sexo === null || $alturaCm === null || $pesoKg === null) {
            return null;
        }

        $normalized = strtoupper(trim($sexo));

        return match ($normalized) {
            self::SEX_MALE => self::forMale($alturaCm, $pesoKg),
            self::SEX_FEMALE => self::forFemale($alturaCm, $pesoKg),
            default => null,
        };
    }

    /** Male Nadler estimate. Height in cm, weight in kg, result in mL. */
    public static function forMale(float $alturaCm, float $pesoKg): float
    {
        $heightM = $alturaCm / 100;

        return (0.3669 * ($heightM ** 3) + 0.03219 * $pesoKg + 0.6041) * 1000;
    }

    /** Female Nadler estimate. Height in cm, weight in kg, result in mL. */
    public static function forFemale(float $alturaCm, float $pesoKg): float
    {
        $heightM = $alturaCm / 100;

        return (0.3561 * ($heightM ** 3) + 0.03308 * $pesoKg + 0.1833) * 1000;
    }
}
