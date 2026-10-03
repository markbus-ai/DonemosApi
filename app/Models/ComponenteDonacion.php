<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponenteDonacion extends Model
{
    use HasFactory;

    protected $table = 'componentes_donacion';

    protected $fillable = ['donacion_id', 'tipo_id', 'vencimiento', 'peso'];

    protected $casts = [
        'peso' => 'decimal:2',
    ];

    public function donacion(): BelongsTo
    {
        return $this->belongsTo(Donacion::class, 'donacion_id');
    }

    public function tipoDonacion(): BelongsTo
    {
        return $this->belongsTo(TipoDonacion::class, 'tipo_id');
    }
}
