<?php

namespace App\Livewire\Controls;

use Livewire\Component;
use App\Support\EventContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Throwable;

class RetiroReingreso extends Component
{
    // ===== Contexto =====
    public ?int $eventoId = null;
    public ?string $eventoTitulo = null;

    // ===== Inputs =====
    public ?string $retiroNumero = null;
    public ?string $reingresoNumero = null;

    // ===== UI / Modal =====
    public bool $modalOpen = false;
    public string $modalType = 'success'; // success | error | info
    public string $modalTitle = '';
    public string $modalBody = '';

    /**
     * Sincroniza eventoId con el contexto del puesto (Station -> evento activo)
     */
    private function syncEventoFromContext(): void
    {
        $this->eventoId = app(EventContext::class)->eventoId();
    }

    public function mount(): void
    {
        $this->syncEventoFromContext();

        if (!$this->eventoId) {
            session()->flash('no_evento_activo', true);
            $this->redirectRoute('eventos.index', navigate: true);
            return;
        }

        $this->eventoTitulo = 'Retiro / Reingreso de controles';
    }

    public function hydrate(): void
    {
        $this->syncEventoFromContext();
    }

    // =========================================================
    //  RETIRAR: marca el registro como RETIRADO (descuenta quórum)
    // =========================================================
    public function retirar(): void
    {
        $this->syncEventoFromContext();
        $eid = (int) ($this->eventoId ?? 0);

        $numRaw = trim((string) $this->retiroNumero);
        $num = ctype_digit($numRaw) ? (int) $numRaw : 0;

        if ($eid <= 0) {
            $this->openModal('Sin evento activo', 'No hay un evento activo en el contexto del puesto.', 'error');
            return;
        }

        if ($num <= 0) {
            $this->openModal('Número inválido', 'Digita un número de control válido.', 'error');
            return;
        }

        try {
            DB::transaction(function () use ($eid, $num) {

                $control = DB::table('controles')
                    ->where('evento_id', $eid)
                    ->where('numero', $num)
                    ->first();

                if (!$control) {
                    $this->openModal('No existe', "No encontré el control #{$num} en este evento.", 'error');
                    return;
                }

                if (($control->estado ?? null) !== 'ASIGNADO') {
                    $this->openModal('No está asignado', "El control #{$num} está en estado '{$control->estado}'. Solo se puede retirar si está ASIGNADO.", 'error');
                    return;
                }

                // Buscamos el registro asociado (por control_id o por snapshot del número)
                $registro = DB::table('registros_checkin')
                    ->where('evento_id', $eid)
                    ->where(function ($q) use ($control, $num) {
                        $q->where('control_id', $control->id)
                            ->orWhere('control_numero_snapshot', $num);
                    })
                    ->orderByDesc('checked_in_at')
                    ->first();

                if (!$registro) {
                    $this->openModal('Sin check-in', "El control #{$num} está ASIGNADO pero no encontré registro de check-in asociado.", 'error');
                    return;
                }

                if (($registro->estado ?? null) !== 'CHECKED_IN') {
                    // Si ya estaba retirado, no hacemos nada (idempotente)
                    $this->openModal('Ya retirado', "El control #{$num} ya está marcado como retirado (estado actual: {$registro->estado}).", 'info');
                    return;
                }

                DB::table('registros_checkin')
                    ->where('id', $registro->id)
                    ->update([
                        'estado' => 'RETIRADO',
                        'updated_at' => now(),
                    ]);

                // Mantenemos el control como ASIGNADO y ligado al registro (para poder reingresar con el mismo #)
                DB::table('controles')
                    ->where('id', $control->id)
                    ->update([
                        'estado' => 'ASIGNADO',
                        'asignado_a_registro_id' => $registro->id,
                        'updated_at' => now(),
                    ]);

                $inmueble = $registro->cabeza_inmueble_snapshot ?? $registro->inmueble_base_id ?? '—';
                $coef = $registro->coef_total_snapshot ?? 0;

                $this->openModal(
                    'Control retirado',
                    "Control #{$num} retirado correctamente.\nInmueble/Grupo: {$inmueble}\nCoef descontado: " . number_format((float) $coef, 4),
                    'success'
                );
            });

        } catch (Throwable $e) {
            $this->openModal('Error', 'Ocurrió un error al retirar el control. Revisa el log para más detalle.', 'error');
            return;
        } finally {
            $this->retiroNumero = null;
        }
    }

    // =========================================================
    //  REINGRESAR: vuelve a CHECKED_IN (vuelve a sumar quórum)
    // =========================================================
    public function reingresar(): void
    {
        $this->syncEventoFromContext();
        $eid = (int) ($this->eventoId ?? 0);

        $numRaw = trim((string) $this->reingresoNumero);
        $num = ctype_digit($numRaw) ? (int) $numRaw : 0;

        if ($eid <= 0) {
            $this->openModal('Sin evento activo', 'No hay un evento activo en el contexto del puesto.', 'error');
            return;
        }

        if ($num <= 0) {
            $this->openModal('Número inválido', 'Digita un número de control válido.', 'error');
            return;
        }

        try {
            DB::transaction(function () use ($eid, $num) {

                $control = DB::table('controles')
                    ->where('evento_id', $eid)
                    ->where('numero', $num)
                    ->first();

                if (!$control) {
                    $this->openModal('No existe', "No encontré el control #{$num} en este evento.", 'error');
                    return;
                }

                if (($control->estado ?? null) !== 'ASIGNADO') {
                    $this->openModal('No está asignado', "El control #{$num} está en estado '{$control->estado}'. Para reingresar debe estar ASIGNADO.", 'error');
                    return;
                }

                $registro = DB::table('registros_checkin')
                    ->where('evento_id', $eid)
                    ->where(function ($q) use ($control, $num) {
                        $q->where('control_id', $control->id)
                            ->orWhere('control_numero_snapshot', $num);
                    })
                    ->orderByDesc('checked_in_at')
                    ->first();

                if (!$registro) {
                    $this->openModal('Sin historial', "No encontré registro asociado al control #{$num}.", 'error');
                    return;
                }

                if (($registro->estado ?? null) === 'CHECKED_IN') {
                    $this->openModal('Ya está activo', "El control #{$num} ya está activo (CHECKED_IN).", 'info');
                    return;
                }

                if (($registro->estado ?? null) !== 'RETIRADO') {
                    $this->openModal('Estado inválido', "El registro asociado está en estado '{$registro->estado}'. Se esperaba RETIRADO.", 'error');
                    return;
                }

                DB::table('registros_checkin')
                    ->where('id', $registro->id)
                    ->update([
                        'estado' => 'CHECKED_IN',
                        // lo marcamos como “reingresó” ahora
                        'checked_in_at' => now(),
                        'checked_in_by_user_id' => Auth::id(),
                        'updated_at' => now(),
                    ]);

                DB::table('controles')
                    ->where('id', $control->id)
                    ->update([
                        'estado' => 'ASIGNADO',
                        'asignado_a_registro_id' => $registro->id,
                        'updated_at' => now(),
                    ]);

                $inmueble = $registro->cabeza_inmueble_snapshot ?? $registro->inmueble_base_id ?? '—';
                $coef = $registro->coef_total_snapshot ?? 0;

                $this->openModal(
                    'Control reingresado',
                    "Control #{$num} reingresado correctamente.\nInmueble/Grupo: {$inmueble}\nCoef sumado: " . number_format((float) $coef, 4),
                    'success'
                );
            });

        } catch (Throwable $e) {
            $this->openModal('Error', 'Ocurrió un error al reingresar el control. Revisa el log para más detalle.', 'error');
            return;
        } finally {
            $this->reingresoNumero = null;
        }
    }

    // ===== Modal helpers =====
    private function openModal(string $title, string $body, string $type = 'success'): void
    {
        $this->modalOpen = true;
        $this->modalTitle = $title;
        $this->modalBody = $body;
        $this->modalType = $type;
    }

    public function closeModal(): void
    {
        $this->modalOpen = false;
        $this->modalTitle = '';
        $this->modalBody = '';
        $this->modalType = 'success';
    }

    public function render()
    {
        return view('livewire.controls.retiro-reingreso')
            ->layout('layouts.app');
    }
}
