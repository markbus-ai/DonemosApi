<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * The hemocentro's calendar day. Storage stays in UTC; every "today" business
 * rule (validity windows, closing a deferral, bookable dates) uses this day so
 * evening use in Argentina is not pushed to tomorrow.
 */
final class Hoy
{
    public static function fecha(): string
    {
        return self::carbon()->toDateString();
    }

    public static function carbon(): Carbon
    {
        return Carbon::parse(now(config('app.business_timezone'))->toDateString());
    }
}
