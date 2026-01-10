<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ControlService
{
    /**
     * Asigna un control (por número) a un registro_checkin.
     * Regla: el control debe existir y estar LIBRE para ese evento.
     * Regla (por ahora): si el registro ya tiene control, NO se cambia aquí.
     */
    public function assignByNumero(int $eventoId, int $registroId, int $numero): void
    {
        DB::transaction(function () use ($eventoId, $registroId, $numero) {

            // 1) Validar que el registro exista y pertenezca al evento
            $registro = DB::table('registros_checkin')
                ->where('id', $registroId)
                ->where('evento_id', $eventoId)
                ->first();

            if (!$registro) {
                throw ValidationException::withMessages([
                    'registro' => 'Registro no existe o no pertenece al evento.',
                ]);
            }

            // ✅ Por ahora: no permitimos reasignar si ya tiene control
            if (!is_null($registro->control_id)) {
                throw ValidationException::withMessages([
                    'control' => 'Este registro ya tiene un control asignado. Para cambiarlo se requiere la acción de “cambiar control” (la haremos después).',
                ]);
            }

            // 2) Bloquear el control (lock) para evitar carreras entre puestos
            $control = DB::table('controles')
                ->where('evento_id', $eventoId)
                ->where('numero', $numero)
                ->lockForUpdate()
                ->first();

            if (!$control) {
                throw ValidationException::withMessages([
                    'control' => "El control #{$numero} no existe para este evento.",
                ]);
            }

            if ($control->estado !== 'LIBRE' || !is_null($control->asignado_a_registro_id)) {
                // Buscar a quién está asignado para dar mensaje claro
                $asignado = DB::table('registros_checkin')
                    ->where('id', $control->asignado_a_registro_id)
                    ->first();

                $msg = "El control #{$numero} ya está asignado.";

                if ($asignado) {
                    // Intentar mostrar el inmueble (mucho más útil que el ID)
                    $padron = DB::table('evento_padron')
                        ->select('inmueble')
                        ->where('id', $asignado->inmueble_base_id)
                        ->first();

                    if ($padron && !empty($padron->inmueble)) {
                        $msg .= " (Asignado a inmueble {$padron->inmueble})";
                    } else {
                        $msg .= " (Registro ID: {$asignado->id})";
                    }
                }

                throw ValidationException::withMessages([
                    'control' => $msg,
                ]);
            }

            // 3) Asignar control al registro
            DB::table('registros_checkin')
                ->where('id', $registroId)
                ->update([
                    'control_id' => $control->id,
                    'control_numero_snapshot' => $control->numero, // opcional pero útil (ya tienes la columna)
                    'updated_at' => now(),
                ]);

            // 4) Marcar control como asignado
            DB::table('controles')
                ->where('id', $control->id)
                ->update([
                    'estado' => 'ASIGNADO',
                    'asignado_a_registro_id' => $registroId,
                    'updated_at' => now(),
                ]);
        });
    }

    /**
     * Libera el control de un registro (para admin).
     */
    public function releaseFromRegistro(int $eventoId, int $registroId): void
    {
        DB::transaction(function () use ($eventoId, $registroId) {

            $registro = DB::table('registros_checkin')
                ->where('id', $registroId)
                ->where('evento_id', $eventoId)
                ->lockForUpdate()
                ->first();

            if (!$registro) {
                throw ValidationException::withMessages([
                    'registro' => 'Registro no existe o no pertenece al evento.',
                ]);
            }

            if (is_null($registro->control_id)) {
                return;
            }

            DB::table('controles')
                ->where('id', $registro->control_id)
                ->lockForUpdate()
                ->update([
                    'estado' => 'LIBRE',
                    'asignado_a_registro_id' => null,
                    'updated_at' => now(),
                ]);

            DB::table('registros_checkin')
                ->where('id', $registroId)
                ->update([
                    'control_id' => null,
                    'control_numero_snapshot' => null,
                    'updated_at' => now(),
                ]);
        });
    }
}
