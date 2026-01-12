<?php

namespace App\Support;

use App\Domain\Event\Models\Evento;

class EventContext
{
    public function __construct(private ?Evento $evento)
    {
    }

    public function eventoId(): ?int
    {
        return 1;
    }

    public function stationId(): ?int
    {
        // Modo evento global: stations no aplican
        return null;
    }
}
