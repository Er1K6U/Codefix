<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Support\EventContext;

class EnsureEventoActivo
{
    public function handle(Request $request, Closure $next)
    {
        $eid = app(EventContext::class)->eventoId();

        if (!$eid) {
            // ✅ Este es el mensaje que tu blade ya convierte en modal bonito
            return redirect()
                ->route('eventos.index')
                ->with('warning', 'No hay evento activo en este puesto. Activa un evento para poder continuar al check-in.');
        }

        return $next($request);
    }
}
