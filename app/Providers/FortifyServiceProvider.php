<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Fortify::ignoreRoutes();
    }

    public function boot(): void
    {
        RateLimiter::for('magic-login-request', function (Request $request) {
            $installation = (string) config('core.installation', 'alpha');
            $email = $request->input('email');
            $normalized = is_string($email) ? Str::lower(trim($email)) : '';

            return [
                Limit::perMinute(5)->by('magic-request:ip:'.$installation.':'.$request->ip()),
                Limit::perMinutes(10, 3)->by('magic-request:email:'.$installation.':'.hash('sha256', $normalized)),
            ];
        });

        RateLimiter::for('magic-login-consume', function (Request $request) {
            return Limit::perMinute(20)->by('magic-consume:ip:'.config('core.installation', 'alpha').':'.$request->ip());
        });
    }
}
