<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Support\EventContext;

class EnsureEventoActivo
{
    public function handle(Request $request, Closure $next)
    {
        // 🎯 Evento activo GLOBAL (sin stations, sin IP)
        $eventoId = app(EventContext::class)->eventoId();

        if (!$eventoId) {
            return redirect()
                ->route('eventos.index')
                ->with('warning', 'Debes activar un evento primero.');
        }

        // (Opcional) dejar el evento disponible en el request
        $request->attributes->set('active_event_id', $eventoId);

        return $next($request);
    }
}
