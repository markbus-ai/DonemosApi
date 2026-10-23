<?php

namespace App\Support;

/** Page size for the paginated lists: default 15, capped at 50. */
final class Paginacion
{
    public const DEFAULT_PER_PAGE = 15;

    public const MAX_PER_PAGE = 50;

    public static function perPage(mixed $requested): int
    {
        if (! is_numeric($requested)) {
            return self::DEFAULT_PER_PAGE;
        }

        return max(1, min((int) $requested, self::MAX_PER_PAGE));
    }
}
