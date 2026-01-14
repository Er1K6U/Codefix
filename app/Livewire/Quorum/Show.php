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

    public int $crowdSize = 160;

    /** @var array<int, array{label:string}> */
    public array $ultimosLlegados = [];

    // si quieres, después lo hacemos persistente por tabla; por ahora usamos cache por evento
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
            $this->ultimosLlegados = [];
            return;
        }

        // 1) Quórum actual = SUM(coef_total_snapshot) de registros cerrados
        $actual = (float) DB::table('registros_checkin')
            ->where('evento_id', $eventoId)
            ->where('estado', 'CHECKED_IN')
            ->sum('coef_total_snapshot');

        // Los coeficientes están en 0-100 en tu mundo, así que es directamente el %
        $this->quorumActual = round($actual, 2);

        // 2) Últimos llegados (los más recientes)
        $rows = DB::table('registros_checkin')
            ->where('evento_id', $eventoId)
            ->where('estado', 'CHECKED_IN')
            ->orderByDesc('checked_in_at')
            ->limit($this->crowdSize)
            ->get(['cabeza_inmueble_snapshot', 'inmueble_base_id']);

        $this->ultimosLlegados = $rows->map(function ($r) {
            $label = $r->cabeza_inmueble_snapshot ?: ('ID-' . $r->inmueble_base_id);
            return ['label' => (string) $label];
        })->values()->all();

        // 3) Máximo alcanzado (persistimos “lo máximo visto” en cache)
        // Importante: si baja el quórum por retiros, el máximo debe quedarse.
        $this->maxCacheKey = 'quorum_max_evento_' . $eventoId;
        $prevMax = (float) cache()->get($this->maxCacheKey, 0);

        $newMax = max($prevMax, $this->quorumActual);
        cache()->put($this->maxCacheKey, $newMax, now()->addDays(7));

        $this->quorumMax = round($newMax, 2);
        $this->quorumRetirado = round(max(0, $this->quorumMax - $this->quorumActual), 2);
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
