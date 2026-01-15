<?php

namespace App\Livewire\Controls;

use Livewire\Component;
use App\Support\EventContext;

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
    public string $modalType = 'ok'; // ok | err
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

        // Extra seguridad (aunque middleware debería bloquear)
        if (!$this->eventoId) {
            session()->flash('no_evento_activo', true);
            redirect()->route('eventos.index')->send();
        }

        // Si quieres luego mostramos el título real del evento desde DB.
        // Por ahora, placeholder para no romper UI:
        $this->eventoTitulo = 'Retiro / Reingreso de controles';
    }

    public function hydrate(): void
    {
        $this->syncEventoFromContext();
    }

    public function closeModal(): void
    {
        $this->modalOpen = false;
        $this->modalTitle = '';
        $this->modalBody = '';
    }

    /**
     * ✅ STUB: retirar control (PASO 8 lo conectamos a DB)
     */
    public function retirar(): void
    {
        $num = trim((string) $this->retiroNumero);

        if ($num === '' || !ctype_digit($num) || (int) $num <= 0) {
            $this->modalType = 'err';
            $this->modalTitle = 'Número inválido';
            $this->modalBody = 'Digita un número de control válido.';
            $this->modalOpen = true;
            return;
        }

        $this->modalType = 'ok';
        $this->modalTitle = 'Modo prueba';
        $this->modalBody = "Recibí retiro de control #{$num}. (En el PASO 8 hacemos la lógica real con DB).";
        $this->modalOpen = true;

        $this->retiroNumero = null;
    }

    /**
     * ✅ STUB: reingresar control (PASO 8 lo conectamos a DB)
     */
    public function reingresar(): void
    {
        $num = trim((string) $this->reingresoNumero);

        if ($num === '' || !ctype_digit($num) || (int) $num <= 0) {
            $this->modalType = 'err';
            $this->modalTitle = 'Número inválido';
            $this->modalBody = 'Digita un número de control válido.';
            $this->modalOpen = true;
            return;
        }

        $this->modalType = 'ok';
        $this->modalTitle = 'Modo prueba';
        $this->modalBody = "Recibí reingreso de control #{$num}. (En el PASO 8 hacemos la lógica real con DB).";
        $this->modalOpen = true;

        $this->reingresoNumero = null;
    }

    public function render()
    {
        return view('livewire.controls.retiro-reingreso')
            ->layout('layouts.app');
    }
}
