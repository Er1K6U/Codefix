<?php

namespace App\Livewire\Event;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Gate;
use App\Domain\Event\Models\Evento;

class Index extends Component
{
    use WithPagination;

    public string $buscar = '';

    public function updatingBuscar()
    {
        $this->resetPage();
    }

    public function render()
    {
        Gate::authorize('eventos.ver');

        $eventos = Evento::query()
            ->where('titulo', 'like', '%' . $this->buscar . '%')
            ->orderBy('fecha_inicio', 'desc')
            ->paginate(10);

        return view('livewire.event.index', [
            'eventos' => $eventos,
        ])->layout('layouts.app');
    }
}
