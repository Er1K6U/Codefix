<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Station;

class EnsureEventoActivo
{
    public function handle(Request $request, Closure $next)
    {
        // Identificamos el "puesto" por IP (misma lógica que Event\Index)
        $ip = $request->ip();

        $station = Station::firstOrCreate(
            ['ip' => $ip],
            ['nombre' => null, 'active_event_id' => null]
        );

        $eventoActivoId = (int) ($station->active_event_id ?? 0);

        if ($eventoActivoId <= 0) {
            return redirect()
                ->route('eventos.index')
                ->with('warning', 'Debes activar un evento primero.');
        }

        // (Opcional pero útil) dejamos el evento activo disponible para el request
        // para que tus pantallas (checkin) lo lean sin repetir consultas:
        $request->attributes->set('active_event_id', $eventoActivoId);
        $request->attributes->set('station_id', $station->id);

        return $next($request);
    }
}
