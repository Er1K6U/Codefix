<?php

namespace App\Livewire\Event;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Gate;
use App\Domain\Event\Models\Evento;
use App\Models\AuditLog;
use App\Models\Station;

class Index extends Component
{
    use WithPagination;

    public string $buscar = '';

    // Estado del "puesto" (PC)
    public ?Station $station = null;
    public ?int $activeEventId = null;

    // Modal de confirmación
    public bool $confirmChange = false;
    public ?int $pendingEventId = null;
    public ?string $pendingEventTitle = null;
    public ?string $currentEventTitle = null;

    public function mount(): void
    {
        $this->loadStation();
    }

    public function updatingBuscar()
    {
        $this->resetPage();
    }

    private function loadStation(): void
    {
        // Identificamos el PC por IP (red local)
        $ip = request()->ip();

        $this->station = Station::firstOrCreate(
            ['ip' => $ip],
            ['nombre' => null, 'active_event_id' => null]
        );

        $this->activeEventId = $this->station->active_event_id;

        if ($this->activeEventId) {
            $this->currentEventTitle = Evento::whereKey($this->activeEventId)->value('titulo');
        } else {
            $this->currentEventTitle = null;
        }
    }

    /**
     * Activar un evento en ESTE puesto (PC).
     * Si ya hay otro activo, pedimos confirmación.
     */
    public function activateForThisStation(int $eventoId): void
    {
        Gate::authorize('eventos.editar');

        $evento = Evento::findOrFail($eventoId);

        // Si ya está activo en este puesto, no hacemos nada
        if ($this->activeEventId === $evento->id) {
            session()->flash('ok', 'Este evento ya está activo en este puesto.');
            return;
        }

        // Si hay uno activo diferente, abrimos modal
        if ($this->activeEventId) {
            $this->pendingEventId = $evento->id;
            $this->pendingEventTitle = $evento->titulo;
            $this->confirmChange = true;
            return;
        }

        // Si no hay activo, activamos directo
        $this->setActiveEvent($evento->id);
        session()->flash('ok', 'Evento activado en este puesto.');
    }

    public function cancelChange(): void
    {
        $this->confirmChange = false;
        $this->pendingEventId = null;
        $this->pendingEventTitle = null;
    }

    public function confirmChangeEvent(): void
    {
        Gate::authorize('eventos.editar');

        if (!$this->pendingEventId) {
            $this->cancelChange();
            return;
        }

        $this->setActiveEvent($this->pendingEventId);

        $this->confirmChange = false;
        $this->pendingEventId = null;
        $this->pendingEventTitle = null;

        session()->flash('ok', 'Evento cambiado para este puesto.');
    }

    public function clearActiveForThisStation(): void
    {
        Gate::authorize('eventos.editar');

        $antes = $this->station?->active_event_id;

        if (!$this->station || !$antes) {
            session()->flash('ok', 'No hay evento activo para limpiar en este puesto.');
            return;
        }

        $this->station->active_event_id = null;
        $this->station->save();

        $this->activeEventId = null;
        $this->currentEventTitle = null;

        AuditLog::create([
            'modulo' => 'stations',
            'accion' => 'active_event_cleared',
            'subject_type' => Station::class,
            'subject_id' => $this->station->id,
            'user_id' => auth()->id(),
            'meta' => [
                'ip' => $this->station->ip,
                'active_event_antes' => $antes,
                'active_event_despues' => null,
            ],
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        session()->flash('ok', 'Evento activo removido de este puesto.');
    }

    private function setActiveEvent(int $eventoId): void
    {
        $antes = $this->station?->active_event_id;

        $this->station->active_event_id = $eventoId;
        $this->station->save();

        $this->activeEventId = $eventoId;
        $this->currentEventTitle = Evento::whereKey($eventoId)->value('titulo');

        AuditLog::create([
            'modulo' => 'stations',
            'accion' => 'active_event_changed',
            'subject_type' => Station::class,
            'subject_id' => $this->station->id,
            'user_id' => auth()->id(),
            'meta' => [
                'ip' => $this->station->ip,
                'active_event_antes' => $antes,
                'active_event_despues' => $eventoId,
            ],
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }

    /**
     * (Opcional) Mantengo tu toggle global por si aún lo quieres usar
     * como "habilitar/deshabilitar" eventos en el sistema.
     */
    public function toggleActivo(int $id): void
    {
        Gate::authorize('eventos.editar');

        $evento = Evento::findOrFail($id);

        $antes = $evento->activo;

        $evento->activo = !$evento->activo;
        $evento->updated_by = auth()->id();
        $evento->save();

        AuditLog::create([
            'modulo' => 'eventos',
            'accion' => 'toggled',
            'subject_type' => Evento::class,
            'subject_id' => $evento->id,
            'user_id' => auth()->id(),
            'meta' => [
                'activo_antes' => $antes,
                'activo_despues' => $evento->activo,
            ],
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        session()->flash('ok', 'Estado del evento actualizado.');
    }

    public function render()
    {
        Gate::authorize('eventos.ver');

        $eventos = Evento::query()
            ->when(
                $this->buscar !== '',
                fn($q) => $q->where('titulo', 'like', '%' . $this->buscar . '%')
            )
            ->orderByDesc('activo')
            ->orderByDesc('fecha_inicio')
            ->paginate(10);

        return view('livewire.event.index', [
            'eventos' => $eventos,
            'activeEventId' => $this->activeEventId,
            'currentEventTitle' => $this->currentEventTitle,
            'confirmChange' => $this->confirmChange,
            'pendingEventTitle' => $this->pendingEventTitle,
        ])->layout('layouts.app');
    }
}
