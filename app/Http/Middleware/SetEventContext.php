<?php

namespace App\Http\Middleware;

use Closure;
use App\Support\EventContext;

class SetEventContext
{
    public function handle($request, Closure $next)
    {
        // Fuerza la resolución del contexto (station + evento) al inicio del request
        app(EventContext::class);

        return $next($request);
    }
}
