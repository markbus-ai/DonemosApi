<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Restriccion extends Model
{
    use HasFactory;

    protected $table = 'restricciones';

    protected $fillable = ['paciente_id', 'motivo_id', 'desde', 'hasta', 'permanente'];

    protected $casts = [
        'permanente' => 'boolean',
    ];

    public function paciente(): BelongsTo
    {
        return $this->belongsTo(Paciente::class, 'paciente_id');
    }

    public function motivo(): BelongsTo
    {
        return $this->belongsTo(Motivo::class, 'motivo_id');
    }

    /**
     * Active as of the given date: `desde <= D` AND
     * (`permanente` OR `hasta IS NULL` OR `D < hasta`).
     *
     * `desde` is inclusive; `hasta` is the return day and is exclusive.
     */
    public function scopeVigente(Builder $query, string|Carbon $fecha): Builder
    {
        $dia = Carbon::parse($fecha)->startOfDay()->toDateString();

        return $query
            ->where('desde', '<=', $dia)
            ->where(function (Builder $query) use ($dia) {
                $query->where('permanente', true)
                    ->orWhereNull('hasta')
                    ->orWhere('hasta', '>', $dia);
            });
    }
}
