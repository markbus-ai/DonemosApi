<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponenteDonacion extends Model
{
    use HasFactory;

    protected $table = 'componentes_donacion';

    protected $fillable = ['donacion_id', 'tipo_id', 'vencimiento', 'peso', 'descartado', 'motivo_descarte'];

    protected $casts = [
        'peso' => 'decimal:2',
        'descartado' => 'boolean',
    ];

    // Only discarded units, regardless of the donation they belong to.
    public function scopeDescartado(Builder $query): Builder
    {
        return $query->where('descartado', true);
    }

    public function donacion(): BelongsTo
    {
        return $this->belongsTo(Donacion::class, 'donacion_id');
    }

    public function tipoDonacion(): BelongsTo
    {
        return $this->belongsTo(TipoDonacion::class, 'tipo_id');
    }
}
