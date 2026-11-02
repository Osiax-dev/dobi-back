<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Le lien de réinitialisation pointe vers le front React.
        ResetPassword::createUrlUsing(function ($user, string $token) {
            return rtrim(config('dobi.frontend_url'), '/').'/reset-password?token='.$token
                .'&email='.urlencode($user->getEmailForPasswordReset());
        });

        // API connectée.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        // Connexion, inscription, mot de passe oublié : anti brute-force.
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(5)->by($r->ip().'|'.strtolower((string) $r->input('email'))));
        // Endpoints publics.
        RateLimiter::for('public-read', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('public-submit', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
    }
}
