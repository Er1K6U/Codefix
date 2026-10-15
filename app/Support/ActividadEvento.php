<?php

namespace App\Support;

use App\Domain\Event\Models\Evento;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Protección contra reimportaciones destructivas (--wipe) sobre una asamblea con actividad.
 *
 * Debe llamarse dentro de la transacción de la importación, antes del primer borrado.
 * Bloquea primero la fila del evento (exclusiva); el check-in la toma compartida antes de crear
 * actividad nueva, así que importación y selección se ordenan siempre igual y no se interbloquean.
 * Lee con bloqueo (SELECT ... FOR UPDATE) las filas del evento en todas las tablas donde
 * escriben el check-in, los poderes y la entrega de controles. En MySQL (InnoDB, REPEATABLE
 * READ) eso bloquea también los huecos del índice por evento_id, así que ninguna operación
 * puede insertar, cambiar o borrar actividad de ese evento hasta que la importación termine:
 * no queda ventana entre la comprobación y el borrado. Las lecturas con bloqueo ven además lo
 * último confirmado, no la foto inicial de la transacción.
 */
class ActividadEvento
{
    /** Siempre en este orden, para que dos importaciones bloqueen igual. */
    private const TABLAS = [
        'registros_checkin',
        'representacion_miembros',
        'representacion_grupos',
        'representacion_miembros_nominal',
        'representacion_grupos_nominal',
        'controles',
    ];

    /**
     * Bloquea la actividad del evento y la cuenta; si hay alguna, lanza ReimportacionBloqueada.
     */
    public static function exigirSinActividad(int $eventoId, string $reemplazo): void
    {
        // Primero la fila del evento, en exclusiva: la selección del check-in la toma compartida
        // antes de crear el primer registro, así que una y otra se esperan sin cruzarse.
        DB::table('eventos')->where('id', $eventoId)->lockForUpdate()->first(['id']);

        $filas = [];
        $filas['registros_checkin'] = DB::table('registros_checkin')->where('evento_id', $eventoId)->lockForUpdate()->get();

        // Con asistencia ya registrada la respuesta es "no": se cuenta el resto sin bloquearlo, para
        // no competir por filas que el check-in, los poderes o los retiros estén usando.
        $bloquear = $filas['registros_checkin']->isEmpty();

        foreach (array_slice(self::TABLAS, 1) as $tabla) {
            $filas[$tabla] = DB::table($tabla)->where('evento_id', $eventoId)->when($bloquear, fn($q) => $q->lockForUpdate())->get();
        }

        $grupoConCheckin = fn($g) => $g->checkin_at !== null || $g->checkin_by !== null
            || $g->control_numero !== null || $g->control_serial !== null;

        $actividad = array_filter([
            'asistencias' => $filas['registros_checkin']->count(),
            'poderes' => $filas['representacion_miembros']->where('es_cabeza', false)->count()
                + $filas['representacion_miembros_nominal']->where('es_cabeza', false)->count(),
            'grupos con check-in' => $filas['representacion_grupos']->filter($grupoConCheckin)->count()
                + $filas['representacion_grupos_nominal']->filter($grupoConCheckin)->count(),
            'controles entregados' => $filas['controles']
                ->filter(fn($c) => $c->estado !== 'LIBRE' || $c->asignado_a_registro_id !== null)->count(),
        ]);

        if ($actividad) {
            throw new ReimportacionBloqueada($eventoId, $reemplazo, $actividad);
        }
    }

    /**
     * Deja constancia del intento bloqueado en la auditoría (fuera de la transacción revertida).
     */
    public static function auditarBloqueo(ReimportacionBloqueada $e, string $comando): void
    {
        AuditLog::create([
            'modulo' => 'importaciones',
            'accion' => 'reimportacion_bloqueada',
            'subject_type' => Evento::class,
            'subject_id' => $e->eventoId,
            'user_id' => auth()->id(),
            'meta' => ['comando' => $comando, 'actividad' => $e->actividad],
            'ip' => app()->runningInConsole() ? null : request()->ip(),
            'user_agent' => app()->runningInConsole() ? null : request()->userAgent(),
        ]);
    }
}
