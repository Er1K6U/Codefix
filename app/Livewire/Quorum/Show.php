<?php

namespace App\Livewire\Quorum;

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use App\Support\EventContext;

class Show extends Component
{
    public string $eventoTitulo = 'Quórum';
    public ?string $eventoImagen = null;

    public string $tipoQuorum = 'coeficiente';

    public float $quorumActual = 0.0;
    public float $quorumMax = 0.0;
    public float $quorumRetirado = 0.0;

    // Solo para modo nominal
    public int $personasCheckin = 0;
    public int $personasRetiradas = 0;
    public int $personasTotal = 0;

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
        $this->tipoQuorum = $ctx->tipoQuorum();
        $this->loadEventInfo($ctx);
        $this->refreshData($ctx);
    }

    public function refreshData(EventContext $ctx): void
    {
        $eventoId = $ctx->eventoId();
        $this->tipoQuorum = $ctx->tipoQuorum();

        if (!$eventoId) {
            $this->quorumActual = 0;
            $this->quorumMax = 0;
            $this->quorumRetirado = 0;
            $this->personasCheckin = 0;
            $this->personasRetiradas = 0;
            $this->personasTotal = 0;
            $this->controlesActivos = 0;
            $this->controlesRetirados = 0;
            $this->controlesRetiradosUnicos = 0;
            $this->ultimosLlegados = [];
            return;
        }

        // Controles (igual en ambos modos)
        $this->controlesActivos = (int) DB::table('registros_checkin')
            ->where('evento_id', $eventoId)
            ->where('estado', 'CHECKED_IN')
            ->whereNotNull('control_id')
            ->distinct()
            ->count('control_id');

        $this->controlesRetirados = (int) DB::table('registros_checkin')
            ->where('evento_id', $eventoId)
            ->where('estado', 'RETIRADO')
            ->whereNotNull('control_id')
            ->distinct()
            ->count('control_id');

        $this->controlesRetiradosUnicos = $this->controlesRetirados;

        if ($this->tipoQuorum === 'nominal') {
            // SUM(coef_total_snapshot): incluye votos representados por poderes anexados
            $this->personasCheckin = (int) DB::table('registros_checkin')
                ->where('evento_id', $eventoId)
                ->where('estado', 'CHECKED_IN')
                ->whereNotNull('persona_id')
                ->sum('coef_total_snapshot');

            $this->personasRetiradas = (int) DB::table('registros_checkin')
                ->where('evento_id', $eventoId)
                ->where('estado', 'RETIRADO')
                ->whereNotNull('persona_id')
                ->sum('coef_total_snapshot');

            $this->personasTotal = (int) DB::table('evento_personas')
                ->where('evento_id', $eventoId)
                ->count();

            // quorumActual como % auxiliar (para la barra de progreso)
            $this->quorumActual = $this->personasTotal > 0
                ? round($this->personasCheckin / $this->personasTotal * 100, 2)
                : 0.0;

            $this->quorumRetirado = $this->personasTotal > 0
                ? round($this->personasRetiradas / $this->personasTotal * 100, 2)
                : 0.0;

            $this->quorumMax = round($this->quorumActual + $this->quorumRetirado, 2);

            // Feed: últimas personas nominales
            $rows = DB::table('registros_checkin as rc')
                ->join('evento_personas as ep', 'ep.id', '=', 'rc.persona_id')
                ->where('rc.evento_id', $eventoId)
                ->where('rc.estado', 'CHECKED_IN')
                ->orderByDesc('rc.checked_in_at')
                ->limit($this->feedSize)
                ->get(['ep.nombre', 'ep.cedula']);

            $this->ultimosLlegados = $rows->map(fn($r) => [
                'label' => (string) $r->nombre,
            ])->values()->all();

            $this->maxCacheKey = 'quorum_max_evento_' . $eventoId;
            return;
        }

        // ── MODO COEFICIENTE ──────────────────────────────────────
        $actual = (float) DB::table('registros_checkin')
            ->where('evento_id', $eventoId)
            ->where('estado', 'CHECKED_IN')
            ->sum('coef_total_snapshot');

        $this->quorumActual = round($actual, 2);

        $retirado = (float) DB::table('registros_checkin')
            ->where('evento_id', $eventoId)
            ->where('estado', 'RETIRADO')
            ->sum('coef_total_snapshot');

        $this->quorumRetirado = round($retirado, 2);

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

        $this->quorumMax = round($this->quorumActual + $this->quorumRetirado, 2);
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
