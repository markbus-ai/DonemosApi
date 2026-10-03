<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Autoexclusion extends Model
{
    use HasFactory;

    protected $table = 'autoexclusiones';

    protected $fillable = ['donacion_id', 'motivo', 'created_by'];

    public function donacion(): BelongsTo
    {
        return $this->belongsTo(Donacion::class, 'donacion_id');
    }
}
