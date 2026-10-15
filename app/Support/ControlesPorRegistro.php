<?php

namespace App\Support;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Controles que corresponden a cada registro de check-in de un evento.
 *
 * Equivale exactamente a unir
 *   controles c ON c.evento_id = rc.evento_id
 *               AND (c.id = rc.control_id OR c.numero = rc.control_numero_snapshot)
 * (una fila por cada control distinto que cumpla, incluso si control_id y el número
 * snapshot apuntan a controles diferentes), pero cada búsqueda usa su índice
 * (PRIMARY y evento_id+numero) en vez de recorrer todos los controles del evento por
 * cada registro, que era lo que hacía lenta la condición OR.
 *
 * Columnas: registro_id, control_id, serial.
 */
class ControlesPorRegistro
{
    public static function delEvento(int $eventoId): Builder
    {
        $porId = DB::table('registros_checkin as r')
            ->join('controles as c', function ($join) {
                $join->on('c.id', '=', 'r.control_id')
                    ->on('c.evento_id', '=', 'r.evento_id');
            })
            ->where('r.evento_id', $eventoId)
            ->select('r.id as registro_id', 'c.id as control_id', 'c.serial');

        $porNumero = DB::table('registros_checkin as r')
            ->join('controles as c', function ($join) {
                $join->on('c.evento_id', '=', 'r.evento_id')
                    ->on('c.numero', '=', 'r.control_numero_snapshot');
            })
            ->where('r.evento_id', $eventoId)
            ->select('r.id as registro_id', 'c.id as control_id', 'c.serial');

        // UNION (no UNION ALL): un control que cumple ambas condiciones cuenta una sola vez, como con OR.
        return $porId->union($porNumero);
    }
}
