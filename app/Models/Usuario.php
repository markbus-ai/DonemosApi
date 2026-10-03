<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'usuarios';

    protected $fillable = ['username', 'password_hash', 'rol_id', 'sede_id'];

    protected $hidden = ['password_hash'];

    protected function casts(): array
    {
        return [
            'rol_id' => 'integer',
            'sede_id' => 'integer',
        ];
    }

    // Staff credentials live in password_hash, not the framework default "password".
    public function getAuthPasswordName(): string
    {
        return 'password_hash';
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Rol::class, 'rol_id');
    }

    // Home site of the staff member; NULL means global super-admin.
    public function sede(): BelongsTo
    {
        return $this->belongsTo(Sede::class, 'sede_id');
    }

    // Global access check; role-driven, no new auth system.
    public function esSuperAdmin(): bool
    {
        return $this->sede_id === null && $this->rol?->nombre === 'super-admin';
    }

    public function autorizacionesExtraordinarias(): HasMany
    {
        return $this->hasMany(AutorizacionExtraordinaria::class, 'usuario_id');
    }
}
