<?php

namespace App\Support;

use App\Domain\Event\Models\Evento;

class EventContext
{
    public function __construct(private ?Evento $evento = null)
    {
        // compat
    }

    public function eventoId(): ?int
    {
        // ✅ “Puesto” por sesión (independiente por usuario/login)
        $id = session('active_event_id');

        if (!$id)
            return null;

        // Solo si el evento existe y está habilitado
        return Evento::where('id', $id)
            ->where('activo', true)
            ->value('id');
    }

    public function stationId(): ?int
    {
        // Ya no usamos stations aquí
        return null;
    }
}
