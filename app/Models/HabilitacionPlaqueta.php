<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabilitacionPlaqueta extends Model
{
    use HasFactory;

    protected $table = 'habilitaciones_plaquetas';

    protected $fillable = ['paciente_id', 'desde', 'hasta', 'created_by'];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    /**
     * Active as of the given date: `desde <= D < hasta`.
     *
     * `desde` is inclusive; `hasta` is the expiry day and is exclusive. Reads
     * never mutate: expiry does not auto-renew.
     */
    public function scopeVigente(Builder $query, string|Carbon $fecha): Builder
    {
        $dia = Carbon::parse($fecha)->startOfDay()->toDateString();

        return $query
            ->where('desde', '<=', $dia)
            ->where('hasta', '>', $dia);
    }
}
