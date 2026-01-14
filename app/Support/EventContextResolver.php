<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class EventContextResolver
{
    public function eventoId(): ?int
    {
        // ✅ MODO ESTRICTO: el evento activo sale SOLO del puesto (stations).
        // Si el puesto no tiene evento asignado, devolvemos null (y check-in debe bloquear).

        $ip = request()->ip();

        $station = DB::table('stations')
            ->select('active_event_id', 'active_evento_id')
            ->where('ip', $ip)
            ->first();

        if (!$station) {
            return null;
        }

        $eid = $station->active_event_id ?? $station->active_evento_id ?? null;

        return $eid ? (int) $eid : null;
    }

    public function stationId(): ?int
    {
        // Opcional: si luego quieres usarlo, aquí lo dejamos disponible.
        $ip = request()->ip();

        $station = DB::table('stations')
            ->select('id')
            ->where('ip', $ip)
            ->first();

        return $station?->id ? (int) $station->id : null;
    }
}
