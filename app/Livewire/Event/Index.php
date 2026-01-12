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

        // (Recomendado) dejamos station_id en sesión para todo el sistema
        session(['station_id' => $this->station->id]);

        // Preferimos el campo viejo (active_event_id) y si no existe, usamos el nuevo (active_evento_id)
        $this->activeEventId = (int) (
            $this->station->active_event_id
            ?? $this->station->active_evento_id
            ?? 0
        ) ?: null;

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
        // ✅ Permiso específico para activar evento en puesto
        Gate::authorize('eventos.activar_puesto');

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
        // ✅ Permiso específico para cambiar el evento del puesto
        Gate::authorize('eventos.activar_puesto');

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
        // ✅ Permiso específico para limpiar el evento del puesto
        Gate::authorize('eventos.activar_puesto');

        if (!$this->station) {
            session()->flash('ok', 'No hay puesto detectado.');
            return;
        }

        $antes = (int) (
            $this->station->active_event_id
            ?? $this->station->active_evento_id
            ?? 0
        ) ?: null;

        if (!$antes) {
            session()->flash('ok', 'No hay evento activo para limpiar en este puesto.');
            return;
        }

        // ✅ Limpiamos ambos campos por compatibilidad + metadata
        $this->station->active_event_id = null;
        $this->station->active_evento_id = null;
        $this->station->activated_at = null;
        $this->station->activated_by = null;
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
        if (!$this->station) {
            // Por seguridad, recargamos la estación si algo raro pasó
            $this->loadStation();
        }

        $antes = (int) (
            $this->station->active_event_id
            ?? $this->station->active_evento_id
            ?? 0
        ) ?: null;

        // ✅ Guardamos ambos campos (viejo y nuevo) + metadata
        $this->station->active_event_id = $eventoId;
        $this->station->active_evento_id = $eventoId; // nuevo campo
        $this->station->activated_at = now();
        $this->station->activated_by = auth()->id();
        $this->station->save();

        // (Recomendado) estación en sesión para todo el sistema
        session(['station_id' => $this->station->id]);

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
                !auth()->user()->can('eventos.editar'),
                fn($q) => $q->where('activo', true)
            )
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
