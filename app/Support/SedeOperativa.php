<?php

namespace App\Support;

use App\Models\Sede;

/**
 * Sede where a turno or donation happens: the explicit payload `sede_id`,
 * then the operator's home sede, then the flagged default sede.
 */
final class SedeOperativa
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function resolver(array $data): Sede
    {
        if (! empty($data['sede_id'])) {
            return Sede::findOrFail($data['sede_id']);
        }

        $staffSedeId = auth('staff')->user()?->sede_id;

        if ($staffSedeId !== null) {
            return Sede::findOrFail($staffSedeId);
        }

        return Sede::porDefecto();
    }
}
