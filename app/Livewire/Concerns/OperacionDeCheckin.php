<?php

namespace App\Livewire\Concerns;

use Illuminate\Database\DetectsConcurrencyErrors;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Comprobaciones comunes de las pantallas de check-in y de retiro/reingreso.
 */
trait OperacionDeCheckin
{
    use DetectsConcurrencyErrors;

    /**
     * Livewire lo ejecuta en cada petición: la carga inicial y cada acción interna, que no
     * vuelven a pasar por el middleware de la ruta. Solo ADMIN y OPERADOR (checkin.usar).
     */
    public function bootOperacionDeCheckin(): void
    {
        $usuario = auth()->user();

        abort_unless($usuario && (bool) ($usuario->activo ?? true), 403, 'Tu usuario está desactivado. Contacta al administrador.');
        Gate::authorize('checkin.usar');
    }

    /**
     * Mensaje para el operador ante un error inesperado; los conflictos de concurrencia
     * (interbloqueo, espera agotada) se explican como reintentables. Siempre queda en el log.
     */
    protected function mensajeDeError(Throwable $e, string $operacion): string
    {
        report($e);

        if ($this->causedByConcurrencyError($e)) {
            return "Otra estación estaba usando estos mismos datos y no se pudo {$operacion}. No se guardó nada; intenta de nuevo.";
        }

        return app()->isLocal()
            ? "Error al {$operacion}: " . $e->getMessage()
            : "Ocurrió un error al {$operacion}. No se guardó nada; quedó registrado en el log.";
    }
}
