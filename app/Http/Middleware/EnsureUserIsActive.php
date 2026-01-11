<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        // Si no hay sesión, normal
        if (!$user) {
            return $next($request);
        }

        // Si el campo no existe, asumimos activo (por seguridad de compatibilidad)
        $activo = (bool) ($user->activo ?? true);

        if (!$activo) {
            Auth::logout();

            // invalida sesión
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            abort(403, 'Tu usuario está desactivado. Contacta al administrador.');
        }

        return $next($request);
    }
}
