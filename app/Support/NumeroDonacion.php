<?php

namespace App\Support;

use App\Models\Sede;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single source of truth for the donation number layout:
 * year (4) + locality code (3) + sequence (6).
 *
 * The layout is isolated here so padding can change later without touching
 * the allocator and without backfilling existing rows.
 */
final class NumeroDonacion
{
    public const YEAR_PAD = 4;

    public const LOCALITY_PAD = 3;

    public const SEQ_PAD = 6;

    public static function format(int $anio, string $codigoLocalidad, int $secuencia): string
    {
        return self::pad($anio, self::YEAR_PAD)
            .self::pad($codigoLocalidad, self::LOCALITY_PAD)
            .self::pad($secuencia, self::SEQ_PAD);
    }

    /**
     * Allocate the next number for a sede/year.
     *
     * MUST run inside the caller's transaction. The counter row is created if
     * missing and atomically incremented, then read back; the UNIQUE counter
     * row and UNIQUE(numero_donacion) are the real backstops (lockForUpdate is
     * a no-op on SQLite).
     *
     * @throws ValidationException when the sede has no locality code.
     */
    public static function allocate(Sede $sede, int $anio): string
    {
        $codigo = $sede->codigo_localidad;

        if ($codigo === null || $codigo === '') {
            throw ValidationException::withMessages([
                'sede_id' => 'La sede no tiene código de localidad configurado.',
            ]);
        }

        // The code fills exactly LOCALITY_PAD digits and must identify one
        // sede once padded ("1" and "001" would mint the same numbers).
        $padded = self::pad((string) $codigo, self::LOCALITY_PAD);
        $colision = Sede::where('id', '!=', $sede->id)->whereNotNull('codigo_localidad')->pluck('codigo_localidad')
            ->contains(fn ($otro) => self::pad((string) $otro, self::LOCALITY_PAD) === $padded);

        if (! preg_match('/^\d{1,'.self::LOCALITY_PAD.'}$/', (string) $codigo) || $colision) {
            throw ValidationException::withMessages([
                'sede_id' => 'El código de localidad de la sede es inválido o está repetido; revisá la configuración de sedes.',
            ]);
        }

        DB::table('secuencias_donacion')->insertOrIgnore([
            'sede_id' => $sede->id,
            'anio' => $anio,
            'ultimo_numero' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('secuencias_donacion')
            ->where('sede_id', $sede->id)
            ->where('anio', $anio)
            ->increment('ultimo_numero');

        $secuencia = (int) DB::table('secuencias_donacion')
            ->where('sede_id', $sede->id)
            ->where('anio', $anio)
            ->value('ultimo_numero');

        if ($secuencia >= 10 ** self::SEQ_PAD) {
            throw ValidationException::withMessages([
                'sede_id' => 'Se agotó la numeración de donaciones de la sede para este año.',
            ]);
        }

        return self::format($anio, (string) $codigo, $secuencia);
    }

    private static function pad(int|string $value, int $length): string
    {
        return str_pad((string) $value, $length, '0', STR_PAD_LEFT);
    }
}
