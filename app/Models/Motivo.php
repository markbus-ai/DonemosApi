<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Motivo extends Model
{
    use HasFactory;

    protected $table = 'motivos';

    protected $fillable = ['nombre', 'codigo', 'plazo_meses'];

    protected $casts = [
        'plazo_meses' => 'integer',
    ];

    public function observaciones(): HasMany
    {
        return $this->hasMany(Observacion::class, 'motivo_id');
    }

    public function restricciones(): HasMany
    {
        return $this->hasMany(Restriccion::class, 'motivo_id');
    }
}
