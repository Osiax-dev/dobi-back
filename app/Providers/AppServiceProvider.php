<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\URL;
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

        // E-mail de confirmation d'adresse (en français, lien valable 24 h).
        VerifyEmail::toMailUsing(function ($notifiable, string $url) {
            $link = URL::temporarySignedRoute('verification.verify', now()->addHours(24), [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ]);

            return (new MailMessage)
                ->subject('Confirmez votre adresse e-mail - DOBI')
                ->view(['emails.verify', 'emails.verify-text'], ['name' => $notifiable->name, 'url' => $link]);
        });

        // E-mail de réinitialisation du mot de passe, aux couleurs de DOBI.
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = rtrim(config('dobi.frontend_url'), '/').'/reset-password?token='.$token.'&email='.urlencode($notifiable->getEmailForPasswordReset());

            return (new MailMessage)
                ->subject('Réinitialisation de votre mot de passe - DOBI')
                ->view(['emails.reset', 'emails.reset-text'], [
                    'name' => $notifiable->name,
                    'url' => $url,
                    'minutes' => config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60),
                ]);
        });

        // API connectée.
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
        // Connexion, inscription, mot de passe oublié : anti brute-force.
        RateLimiter::for('auth', fn (Request $r) => Limit::perMinute(5)->by($r->ip().'|'.strtolower((string) $r->input('email'))));
        // Renvoi du lien de vérification.
        RateLimiter::for('verification', fn (Request $r) => Limit::perMinute(3)->by($r->ip().'|'.strtolower((string) $r->input('email'))));
        // Endpoints publics.
        RateLimiter::for('public-read', fn (Request $r) => Limit::perMinute(60)->by($r->ip()));
        RateLimiter::for('public-submit', fn (Request $r) => Limit::perMinute(30)->by($r->ip()));
    }
}
