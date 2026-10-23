<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
