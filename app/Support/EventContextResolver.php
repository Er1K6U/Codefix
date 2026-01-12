<?php

namespace App\Support;

use App\Domain\Event\Models\Evento;

class EventContext
{
    public function eventoId(): ?int
    {
        return Evento::where('is_active', true)->value('id');
    }

    public function stationId(): ?int
    {
        // Ya no usamos stations en modo "evento global".
        return null;
    }
}
