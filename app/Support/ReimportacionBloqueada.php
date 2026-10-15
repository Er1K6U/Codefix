<?php

namespace App\Support;

use RuntimeException;

/** Se lanza dentro de la transacción para revertirla antes de borrar nada. */
class ReimportacionBloqueada extends RuntimeException
{
    public function __construct(public readonly int $eventoId, string $reemplazo, public readonly array $actividad)
    {
        $detalle = implode(', ', array_map(fn($k, $n) => "{$k}: {$n}", array_keys($actividad), $actividad));

        parent::__construct(
            "No se reemplazó {$reemplazo}: la asamblea #{$eventoId} ya tiene actividad ({$detalle}). "
            . "Se canceló sin borrar nada, para no perder poderes, asistencia ni controles entregados."
        );
    }
}
