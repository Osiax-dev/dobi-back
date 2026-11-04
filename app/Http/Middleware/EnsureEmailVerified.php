<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bloque toute route protégée tant que l'adresse e-mail n'est pas confirmée (même avec un token déjà émis). */
class EnsureEmailVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasVerifiedEmail()) {
            return response()->json([
                'message' => 'Confirmez votre adresse e-mail pour accéder à DOBI.',
                'code' => 'email_not_verified',
            ], 403);
        }

        return $next($request);
    }
}
