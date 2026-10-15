<?php

namespace App\Support;

use RuntimeException;

/**
 * Rechazo de una operación del check-in con un mensaje para el operador.
 * Lanzada dentro de una transacción, la revierte completa (nada queda a medias).
 */
class OperacionRechazada extends RuntimeException
{
}
