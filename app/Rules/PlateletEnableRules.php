<?php

namespace App\Rules;

use Carbon\Carbon;

/**
 * PlateletEnableRules - pure domain object for the platelet-enable window.
 *
 * The active window is `desde <= D < hasta`: `desde` is inclusive, `hasta` is
 * the expiry day and is exclusive. The default duration comes from
 * config/platelet_enable.php. Expiry is read-only and never auto-renews, and a
 * re-enable never shortens an unexpired prior `hasta`.
 *
 * This class never persists or reads the database.
 */
final class PlateletEnableRules
{
    private int $meses;

    public function __construct(?int $meses = null)
    {
        $this->meses = $meses ?? (int) config('platelet_enable.meses', 6);
    }

    /** Default enabling duration in months. */
    public function meses(): int
    {
        return $this->meses;
    }

    /** Default expiry: `desde` plus the configured number of months. */
    public function defaultHasta(string|Carbon $desde): string
    {
        return Carbon::parse($desde)->startOfDay()->addMonths($this->meses)->toDateString();
    }

    /**
     * Resolve the expiry for a new window. An explicit `$requested` wins unless
     * the prior window still has a later, unexpired `$priorHasta`; expiry is
     * never brought forward.
     */
    public function resolveHasta(?string $requested, string|Carbon $desde, ?string $priorHasta): string
    {
        $proposed = $requested ?? $this->defaultHasta($desde);

        if ($priorHasta !== null && Carbon::parse($priorHasta)->greaterThan(Carbon::parse($proposed))) {
            return Carbon::parse($priorHasta)->toDateString();
        }

        return Carbon::parse($proposed)->toDateString();
    }

    /**
     * Active as of `$on`: `desde <= D < hasta`. A missing bound is never active.
     */
    public function isActive(?string $desde, ?string $hasta, string|Carbon $on): bool
    {
        if ($desde === null || $hasta === null) {
            return false;
        }

        $dia = Carbon::parse($on)->startOfDay();

        return Carbon::parse($desde)->startOfDay()->lessThanOrEqualTo($dia)
            && $dia->lessThan(Carbon::parse($hasta)->startOfDay());
    }
}
