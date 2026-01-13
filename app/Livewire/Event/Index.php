<?php

namespace App\Livewire\Event;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use App\Domain\Event\Models\Evento;

class Index extends Component
{
    use WithPagination;

    public string $buscar = '';

    // “Puesto” ahora es por sesión (independiente por PC/navegador)
    public ?int $activeEventId = null;
    public ?string $currentEventTitle = null;

    // modal confirmación cambio
    public bool $confirmChange = false;
    public ?int $pendingEventId = null;
    public ?string $pendingEventTitle = null;

    // 🧹 modal confirmación eliminar
    public bool $confirmDelete = false;
    public ?int $deleteEventId = null;
    public ?string $deleteEventTitle = null;

    public function mount(): void
    {
        $this->syncFromSession();
    }

    public function updatedBuscar(): void
    {
        $this->resetPage();
    }

    private function syncFromSession(): void
    {
        $this->activeEventId = session('active_event_id');

        if ($this->activeEventId) {
            $this->currentEventTitle = Evento::where('id', $this->activeEventId)->value('titulo');
        } else {
            $this->currentEventTitle = null;
        }
    }

    // ✅ Activar “en este puesto” (sesión) y mandar a check-in
    public function activateForThisStation(int $eventoId)
    {
        Gate::authorize('eventos.activar_puesto');

        $evento = Evento::findOrFail($eventoId);

        if (!(bool) $evento->activo) {
            session()->flash('warning', 'Este evento está deshabilitado. Primero debes habilitarlo.');
            return;
        }

        if ($this->activeEventId && (int) $this->activeEventId !== (int) $eventoId) {
            $this->confirmChange = true;
            $this->pendingEventId = $eventoId;
            $this->pendingEventTitle = $evento->titulo;
            return;
        }

        session(['active_event_id' => $eventoId]);
        $this->syncFromSession();

        session()->flash('ok', 'Evento activo en este puesto actualizado.');

        return redirect()->route('checkin');
    }

    public function cancelChange(): void
    {
        $this->confirmChange = false;
        $this->pendingEventId = null;
        $this->pendingEventTitle = null;
    }

    public function confirmChangeEvent()
    {
        Gate::authorize('eventos.activar_puesto');

        if (!$this->pendingEventId) {
            $this->cancelChange();
            return;
        }

        $evento = Evento::findOrFail($this->pendingEventId);

        if (!(bool) $evento->activo) {
            session()->flash('warning', 'Este evento está deshabilitado. Primero debes habilitarlo.');
            $this->cancelChange();
            return;
        }

        session(['active_event_id' => (int) $this->pendingEventId]);
        $this->syncFromSession();

        $this->cancelChange();

        session()->flash('ok', 'Evento activo en este puesto actualizado.');

        return redirect()->route('checkin');
    }

    // ✅ Quitar evento activo de “este puesto” (sesión)
    public function clearActiveForThisStation()
    {
        Gate::authorize('eventos.activar_puesto');

        session()->forget('active_event_id');
        $this->syncFromSession();

        session()->flash('ok', 'Evento activo removido de este puesto.');
    }

    // ✅ Habilitar/Deshabilitar (admin)
    public function toggleActivo(int $eventoId)
    {
        Gate::authorize('eventos.editar');

        $evento = Evento::findOrFail($eventoId);
        $evento->activo = !$evento->activo;
        $evento->updated_by = auth()->id();
        $evento->save();

        session()->flash('ok', 'Estado del evento actualizado.');

        if ((int) ($this->activeEventId ?? 0) === (int) $evento->id && !(bool) $evento->activo) {
            session()->forget('active_event_id');
            $this->syncFromSession();
        }
    }

    // =========================
    // 🧹 ELIMINAR EVENTO (ADMIN)
    // =========================

    public function requestDeleteEvent(int $eventoId): void
    {
        // 🔒 Solo admin (o quien tenga este permiso)
        Gate::authorize('eventos.editar');

        $evento = Evento::findOrFail($eventoId);

        $this->confirmDelete = true;
        $this->deleteEventId = (int) $evento->id;
        $this->deleteEventTitle = (string) $evento->titulo;
    }

    public function cancelDelete(): void
    {
        $this->confirmDelete = false;
        $this->deleteEventId = null;
        $this->deleteEventTitle = null;
    }

    public function confirmDeleteEvent()
    {
        // 🔒 Solo admin (o quien tenga este permiso)
        Gate::authorize('eventos.editar');

        if (!$this->deleteEventId) {
            $this->cancelDelete();
            return;
        }

        $eventoId = (int) $this->deleteEventId;

        DB::transaction(function () use ($eventoId) {

            // hijos primero
            DB::table('representacion_miembros')->where('evento_id', $eventoId)->delete();
            DB::table('representacion_grupos')->where('evento_id', $eventoId)->delete();
            DB::table('registros_checkin')->where('evento_id', $eventoId)->delete();
            DB::table('controles')->where('evento_id', $eventoId)->delete();
            DB::table('evento_padron')->where('evento_id', $eventoId)->delete();

            // evento al final
            DB::table('eventos')->where('id', $eventoId)->delete();
        });

        // si el evento eliminado estaba activo en este puesto, limpiamos la sesión
        if ((int) (session('active_event_id') ?? 0) === $eventoId) {
            session()->forget('active_event_id');
        }

        $this->cancelDelete();
        $this->syncFromSession();

        session()->flash('ok', 'Evento eliminado completamente. Ya puedes crear uno nuevo.');
        return redirect()->route('eventos.index');
    }

    public function render()
    {
        $eventos = Evento::query()
            ->when($this->buscar !== '', function ($q) {
                $q->where('titulo', 'like', '%' . $this->buscar . '%')
                    ->orWhere('slug', 'like', '%' . $this->buscar . '%');
            })
            ->orderByDesc('fecha_inicio')
            ->paginate(10);

        return view('livewire.event.index', [
            'eventos' => $eventos,
            'activeEventId' => $this->activeEventId,
            'currentEventTitle' => $this->currentEventTitle,
            'confirmChange' => $this->confirmChange,
            'pendingEventTitle' => $this->pendingEventTitle,
            // 🧹 modal eliminar
            'confirmDelete' => $this->confirmDelete,
            'deleteEventTitle' => $this->deleteEventTitle,
        ])->layout('layouts.app');
    }
}
