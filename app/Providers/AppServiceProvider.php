<?php

namespace App\Providers;

use App\Models\Usuario;
use App\Services\Whatsapp\LogWhatsappSender;
use App\Services\Whatsapp\WhatsappSender;
use App\Services\Whatsapp\WhatsPlaySender;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(WhatsappSender::class, fn () => match (config('whatsapp.driver')) {
            'whatsplay' => new WhatsPlaySender,
            default => new LogWhatsappSender,
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A disabled staff member's existing tokens stop working immediately.
        Sanctum::authenticateAccessTokensUsing(
            fn ($token, bool $isValid) => $isValid && ($token->tokenable?->activo ?? false),
        );

        // Admin-only areas; the denial message is the 403 body the clients show.
        Gate::define('ver-reportes', fn (Usuario $usuario) => $usuario->puedeVerReportes()
            ? Response::allow()
            : Response::deny('Tu rol no permite ver estadísticas.'));
        Gate::define('administrar-whatsapp', fn (Usuario $usuario) => $usuario->puedeVerReportes()
            ? Response::allow()
            : Response::deny('Solo un administrador puede vincular WhatsApp.'));

        // Staff credentials are short (2 initials + 4 digits); lock attempts per username and IP.
        RateLimiter::for('login', function (Request $request) {
            // Runs before validation: a non-string username must not crash the limiter.
            $username = $request->input('username');
            $key = Str::lower(is_string($username) ? $username : '').'|'.$request->ip();

            // Only rejected attempts count, so normal logins never lock anyone out.
            return Limit::perMinute(5)
                ->by($key)
                ->after(fn ($response) => $response->getStatusCode() === 401)
                ->response(fn (Request $request, array $headers) => response()->json(
                    ['message' => 'Demasiados intentos fallidos. Esperá un minuto y volvé a intentar.'],
                    429,
                    $headers,
                ));
        });
    }
}
