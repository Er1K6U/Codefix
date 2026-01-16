<?php

namespace App\Livewire\BaseTurning;

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use App\Support\EventContext;

class Index extends Component
{
    public array $rows = [];
    public string $copyText = '';
    public ?string $msg = null;
    public float $totalCoef = 0.0;


    public function mount(): void
    {
        $this->loadRows();
    }

    public function refreshRows(): void
    {
        $this->loadRows();
        $this->msg = 'Actualizado ✅';
        $this->dispatch('bt-toast');
    }

    public function copyAll(): void
    {
        if (!$this->copyText) {
            $this->loadRows();
        }

        $this->dispatch('bt-copy', text: $this->copyText);
        $this->msg = 'Copiado ✅';
        $this->dispatch('bt-toast');
    }

    private function loadRows(): void
    {
        $eid = app(EventContext::class)->eventoId();

        // Traemos solo los que tuvieron check-in real (CHECKED_IN o RETIRADO)
        $lastPerInmueble = DB::table('registros_checkin')
            ->selectRaw('MAX(id) as last_id')
            ->where('evento_id', $eid)
            ->whereIn('estado', ['CHECKED_IN', 'RETIRADO'])
            ->groupBy('inmueble_base_id');

        $items = DB::table('registros_checkin as rc')
            ->joinSub($lastPerInmueble, 'u', function ($join) {
                $join->on('rc.id', '=', 'u.last_id');
            })
            ->leftJoin('evento_padron as ep', 'ep.id', '=', 'rc.inmueble_base_id')
            // Fallback para el codigo del control:
            // - primero por control_id si existe
            // - si no, por numero del snapshot (numero es unico por evento)
            ->leftJoin('controles as c', function ($join) {
                $join->on('c.evento_id', '=', 'rc.evento_id')
                    ->where(function ($q) {
                        $q->whereColumn('c.id', 'rc.control_id')
                            ->orWhereColumn('c.numero', 'rc.control_numero_snapshot');
                    });
            })
            ->where('rc.evento_id', $eid)
            ->whereIn('rc.estado', ['CHECKED_IN', 'RETIRADO'])
            ->orderBy('rc.control_numero_snapshot')
            ->get([
                'rc.id as registro_id',
                'rc.estado',
                'rc.control_numero_snapshot',
                'rc.control_serial_snapshot',
                'rc.coef_total_snapshot',
                'rc.cabeza_inmueble_snapshot',
                'ep.inmueble as inmueble_padron',
                'ep.propietario as propietario_padron',
                'c.serial as control_serial_db',
            ]);

        $this->rows = [];
        $lines = [];
        $this->totalCoef = 0.0;

        foreach ($items as $it) {
            $controlNumero = $it->control_numero_snapshot ?? '';

            // ✅ Código: snapshot si existe, si no el de la tabla controles
            $controlCodigo = $it->control_serial_snapshot ?? ($it->control_serial_db ?? '');

            $inmuebleCabeza = $it->cabeza_inmueble_snapshot ?: ($it->inmueble_padron ?? '');
            $propietario = $it->propietario_padron ?? '';

            // Para sumar total (real)
            if ($it->coef_total_snapshot !== null && $it->coef_total_snapshot !== '') {
                $this->totalCoef += (float) $it->coef_total_snapshot;
            }

            // Para mostrar en formato 3 dígitos
            $coef = $this->formatCoef($it->coef_total_snapshot);

            $row = [
                'control_numero' => $controlNumero,
                'codigo' => $controlCodigo,
                'inmueble' => $inmuebleCabeza,
                'propietario' => $propietario,
                'coef' => $coef,
                'estado' => $it->estado,
            ];

            $this->rows[] = $row;

            // TSV (4 cols + propietario ahora serán 5 cols)
            $lines[] = implode("\t", [
                $row['control_numero'],
                $row['codigo'],
                $row['inmueble'],
                $row['propietario'],
                $row['coef'],
            ]);
        }

        $this->copyText = implode("\n", $lines);
    }

    private function formatCoef($value): string
    {
        if ($value === null || $value === '')
            return '';

        // value puede venir como string "0.21" o decimal
        $n = (float) $value;

        // Convertimos 0.21 => 21, y lo dejamos a 3 dígitos: 021
        $asInt = (int) round($n * 100);

        // 0 => 000, 1 => 001, 21 => 021, 100 => 100
        return str_pad((string) $asInt, 3, '0', STR_PAD_LEFT);
    }

    public function render()
    {
        return view('livewire.base-turning.index')
            ->layout('layouts.app');
    }
}
