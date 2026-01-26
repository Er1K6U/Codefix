<?php

namespace App\Livewire\Quorum;

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use App\Support\EventContext;

class Show extends Component
{
    public string $eventoTitulo = 'Quórum';
    public ?string $eventoImagen = null;

    public float $quorumActual = 0.0;
    public float $quorumMax = 0.0;
    public float $quorumRetirado = 0.0;

    public int $controlesActivos = 0;
    public int $controlesRetirados = 0;
    public int $controlesRetiradosUnicos = 0;

    public int $crowdSize = 160;
    public int $feedSize = 10;

    /** @var array<int, array{label:string}> */
    public array $ultimosLlegados = [];

    // lo dejamos para no “mover el piso”, pero ya no lo usamos para cálculo
    private string $maxCacheKey = '';

    public function mount(EventContext $ctx): void
    {
        $this->loadEventInfo($ctx);
        $this->refreshData($ctx);
    }

    public function refreshData(EventContext $ctx): void
    {
        $eventoId = $ctx->eventoId();

        if (!$eventoId) {
            // Sin evento activo: se queda en 0 pero no rompe
            $this->quorumActual = 0;
            $this->quorumMax = 0;
            $this->quorumRetirado = 0;

            $this->controlesActivos = 0;
            $this->controlesRetirados = 0;
            $this->controlesRetiradosUnicos = 0;

            $this->ultimosLlegados = [];
            return;
        }

        // 1) Quórum actual = SUM(coef_total_snapshot) de CHECKED_IN
        $actual = (float) DB::table('registros_checkin')
            ->where('evento_id', $eventoId)
            ->where('estado', 'CHECKED_IN')
            ->sum('coef_total_snapshot');

        $this->quorumActual = round($actual, 2);

        // ✅ Controles activos (únicos)
        $this->controlesActivos = (int) DB::table('registros_checkin')
            ->where('evento_id', $eventoId)
            ->where('estado', 'CHECKED_IN')
            ->whereNotNull('control_id')
            ->distinct()
            ->count('control_id');

        // 1.1) Retirado real = SUM(coef_total_snapshot) de RETIRADO
        $retirado = (float) DB::table('registros_checkin')
            ->where('evento_id', $eventoId)
            ->where('estado', 'RETIRADO')
            ->sum('coef_total_snapshot');

        $this->quorumRetirado = round($retirado, 2);

        // ✅ Controles retirados (únicos)
        $this->controlesRetirados = (int) DB::table('registros_checkin')
            ->where('evento_id', $eventoId)
            ->where('estado', 'RETIRADO')
            ->whereNotNull('control_id')
            ->distinct()
            ->count('control_id');

        // ⚠️ La dejo por compatibilidad (misma lógica que controlesRetirados)
        $this->controlesRetiradosUnicos = $this->controlesRetirados;

        // 2) Últimos llegados (los más recientes) - CHECKED_IN
        $rows = DB::table('registros_checkin')
            ->where('evento_id', $eventoId)
            ->where('estado', 'CHECKED_IN')
            ->orderByDesc('checked_in_at')
            ->limit($this->feedSize)
            ->get(['cabeza_inmueble_snapshot', 'inmueble_base_id']);

        $this->ultimosLlegados = $rows->map(function ($r) {
            $label = $r->cabeza_inmueble_snapshot ?: ('ID-' . $r->inmueble_base_id);
            return ['label' => (string) $label];
        })->values()->all();

        // 3) Máximo = actual + retirado (robusto, depende de DB, no de cache)
        $this->quorumMax = round($this->quorumActual + $this->quorumRetirado, 2);

        // (opcional) dejamos la key asignada por si en el futuro quieres reactivar cache
        $this->maxCacheKey = 'quorum_max_evento_' . $eventoId;
    }

    private function loadEventInfo(EventContext $ctx): void
    {
        $eventoId = $ctx->eventoId();

        if (!$eventoId) {
            $this->eventoTitulo = 'Quórum';
            $this->eventoImagen = null;
            return;
        }

        $evento = DB::table('eventos')->where('id', $eventoId)->first(['titulo', 'imagen']);
        $this->eventoTitulo = $evento?->titulo ?? 'Quórum';
        $this->eventoImagen = $evento?->imagen ?: null;
    }

    public function render()
    {
        return view('livewire.quorum.show')
            ->layout('layouts.app');
    }
}
