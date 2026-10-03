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
            $key = Str::lower((string) $request->input('username')).'|'.$request->ip();

            return Limit::perMinute(5)->by($key);
        });
    }
}
