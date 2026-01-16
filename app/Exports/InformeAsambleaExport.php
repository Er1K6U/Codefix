<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\FromArray;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;


class InformeAsambleaExport implements WithMultipleSheets
{
    public function __construct(public int $eventoId)
    {
    }

    public function sheets(): array
    {
        return [
            new InformeResumenSheet($this->eventoId),
            new InformeAsistenciaSheet($this->eventoId),
            new InformeQuorumSheet($this->eventoId),
            new InformePoderesSheet($this->eventoId),
            new InformePoderesDetalleSheet($this->eventoId),
        ];
    }
}

class InformeResumenSheet implements FromArray, WithTitle, \Maatwebsite\Excel\Concerns\WithColumnFormatting
{
    public function __construct(public int $eventoId)
    {
    }

    public function title(): string
    {
        return 'Resumen';
    }

    public function columnFormats(): array
    {
        // Columna B = valores numéricos del resumen
        return [
            'C' => '0.00', // coef cabeza
            'F' => '0.00', // coef apoderado
        ];
    }

    public function array(): array
    {
        $evento = DB::table('eventos')->where('id', $this->eventoId)->first();
        $titulo = $evento->titulo ?? 'Evento';
        $fecha = now()->format('Y-m-d H:i');

        // Tomamos 1 registro por inmueble (último estado válido) igual que Base Turning
        $lastPerInmueble = DB::table('registros_checkin')
            ->selectRaw('MAX(id) as last_id')
            ->where('evento_id', $this->eventoId)
            ->whereIn('estado', ['CHECKED_IN', 'RETIRADO'])
            ->groupBy('inmueble_base_id');

        $items = DB::table('registros_checkin as rc')
            ->joinSub($lastPerInmueble, 'u', function ($join) {
                $join->on('rc.id', '=', 'u.last_id');
            })
            ->where('rc.evento_id', $this->eventoId)
            ->whereIn('rc.estado', ['CHECKED_IN', 'RETIRADO'])
            ->get(['rc.estado', 'rc.coef_total_snapshot']);

        $presentes = 0;
        $retirados = 0;
        $totalInmuebles = $items->count();

        foreach ($items as $it) {
            $coef = $this->coefReal($it->coef_total_snapshot);

            if ($it->estado === 'CHECKED_IN')
                $presentes += $coef;
            if ($it->estado === 'RETIRADO')
                $retirados += $coef;
        }

        $presentes = round($presentes, 2);
        $retirados = round($retirados, 2);
        $totalCoef = round($presentes + $retirados, 2);

        return [
            ['COEFIX · INFORME DE ASAMBLEA'],
            ['Evento:', $titulo],
            ['Generado:', $fecha],
            [''],
            ['RESUMEN GENERAL'],
            ['Inmuebles (con registro válido):', $totalInmuebles],
            ['Coeficiente presente (CHECKED_IN):', $presentes],
            ['Coeficiente retirado (RETIRADO):', $retirados],
            ['Coeficiente total (presente + retirado):', $totalCoef],
            [''],
            ['Notas:'],
            ['- Los coeficientes se calculan usando coef_total_snapshot y se muestran con 2 decimales.'],
            ['- Este resumen toma el último estado válido por inmueble (CHECKED_IN o RETIRADO).'],
        ];
    }
    private function coefReal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        // Ej: 0.2159 -> 0.22
        return round((float) $value, 2);
    }

}

class InformeQuorumSheet implements FromArray, WithTitle, \Maatwebsite\Excel\Concerns\WithColumnFormatting
{
    public function __construct(public int $eventoId)
    {
    }

    public function title(): string
    {
        return 'Quórum';
    }

    public function columnFormats(): array
    {
        // Columna B (valores) con 2 decimales
        return [
            'B' => '0.00',
        ];
    }

    public function array(): array
    {
        // Tomamos 1 registro por inmueble (último estado válido)
        $lastPerInmueble = DB::table('registros_checkin')
            ->selectRaw('MAX(id) as last_id')
            ->where('evento_id', $this->eventoId)
            ->whereIn('estado', ['CHECKED_IN', 'RETIRADO'])
            ->groupBy('inmueble_base_id');

        $items = DB::table('registros_checkin as rc')
            ->joinSub($lastPerInmueble, 'u', function ($join) {
                $join->on('rc.id', '=', 'u.last_id');
            })
            ->leftJoin('evento_padron as ep', 'ep.id', '=', 'rc.inmueble_base_id')
            ->where('rc.evento_id', $this->eventoId)
            ->whereIn('rc.estado', ['CHECKED_IN', 'RETIRADO'])
            ->orderBy('rc.control_numero_snapshot')
            ->get([
                'rc.estado',
                'rc.coef_total_snapshot',
                'rc.cabeza_inmueble_snapshot',
                'ep.inmueble as inmueble_padron',
                'ep.propietario as propietario_padron',
            ]);

        $presentes = [];
        $retirados = [];
        $coefPresente = 0.0;
        $coefRetirado = 0.0;

        foreach ($items as $it) {
            $inmueble = $it->cabeza_inmueble_snapshot ?: ($it->inmueble_padron ?? '');
            $propietario = $it->propietario_padron ?? '';
            $coef = $this->coefReal($it->coef_total_snapshot);

            if ($it->estado === 'CHECKED_IN') {
                $coefPresente += $coef;
                $presentes[] = [$inmueble, $propietario, $coef];
            }

            if ($it->estado === 'RETIRADO') {
                $coefRetirado += $coef;
                $retirados[] = [$inmueble, $propietario, $coef];
            }
        }

        $coefPresente = round($coefPresente, 2);
        $coefRetirado = round($coefRetirado, 2);
        $coefTotal = round($coefPresente + $coefRetirado, 2);

        $rows = [];
        $rows[] = ['QUÓRUM (SNAPSHOT ACTUAL)'];
        $rows[] = ['Calculado con el último estado válido por inmueble. Presente = CHECKED_IN.'];
        $rows[] = [''];

        $rows[] = ['Coeficiente presente (CHECKED_IN):', $coefPresente];
        $rows[] = ['Coeficiente retirado (RETIRADO):', $coefRetirado];
        $rows[] = ['Coeficiente total (presente + retirado):', $coefTotal];
        $rows[] = [''];

        $rows[] = ['PRESENTES'];
        $rows[] = ['Inmueble cabeza', 'Propietario', 'Coef'];
        foreach ($presentes as $r)
            $rows[] = $r;

        $rows[] = [''];
        $rows[] = ['RETIRADOS'];
        $rows[] = ['Inmueble cabeza', 'Propietario', 'Coef'];
        foreach ($retirados as $r)
            $rows[] = $r;

        return $rows;
    }

    private function coefReal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        return round((float) $value, 2);
    }
}

class InformeAsistenciaSheet implements FromArray, WithTitle, \Maatwebsite\Excel\Concerns\WithColumnFormatting
{
    public function __construct(public int $eventoId)
    {
    }

    public function title(): string
    {
        return 'Asistencia';
    }
    public function columnFormats(): array
    {
        // Columna E = Coeficiente con 2 decimales (ej: 5.36)
        return [
            'E' => '0.00',
        ];
    }

    public function array(): array
    {
        $rows = [];

        // Encabezado explicativo (como te gusta, bien presentado)
        $rows[] = ['ASISTENCIA (BASE TURNING)'];
        $rows[] = ['Listado consolidado por inmueble cabeza. Incluye CHECKED_IN y RETIRADO.'];
        $rows[] = [''];
        $rows[] = ['# Control', 'Código', 'Inmueble cabeza', 'Propietario', 'Coef', 'Estado', 'Hora check-in', 'Hora retiro', 'Hora reingreso'];

        // 1 fila por inmueble (último registro válido)
        $lastPerInmueble = DB::table('registros_checkin')
            ->selectRaw('MAX(id) as last_id')
            ->where('evento_id', $this->eventoId)
            ->whereIn('estado', ['CHECKED_IN', 'RETIRADO'])
            ->groupBy('inmueble_base_id');
        $items = DB::table('registros_checkin as rc')
            ->joinSub($lastPerInmueble, 'u', function ($join) {
                $join->on('rc.id', '=', 'u.last_id');
            })
            ->leftJoin('evento_padron as ep', 'ep.id', '=', 'rc.inmueble_base_id')
            ->leftJoin('controles as c', function ($join) {
                $join->on('c.evento_id', '=', 'rc.evento_id')
                    ->where(function ($q) {
                        $q->whereColumn('c.id', 'rc.control_id')
                            ->orWhereColumn('c.numero', 'rc.control_numero_snapshot');
                    });
            })
            ->where('rc.evento_id', $this->eventoId)
            ->whereIn('rc.estado', ['CHECKED_IN', 'RETIRADO'])
            ->orderBy('rc.control_numero_snapshot')
            ->get([
                'rc.estado',
                'rc.control_numero_snapshot',
                'rc.control_serial_snapshot',
                'rc.coef_total_snapshot',
                'rc.cabeza_inmueble_snapshot',

                // ✅ horas
                'rc.checked_in_at',
                'rc.retirado_at',
                'rc.reingreso_at',

                'ep.inmueble as inmueble_padron',
                'ep.propietario as propietario_padron',
                'c.serial as control_serial_db',
            ]);

        foreach ($items as $it) {
            $controlNumero = $it->control_numero_snapshot ?? '';
            $codigo = $it->control_serial_snapshot ?? ($it->control_serial_db ?? '');
            $inmuebleCabeza = $it->cabeza_inmueble_snapshot ?: ($it->inmueble_padron ?? '');
            $propietario = $it->propietario_padron ?? '';
            $coef3 = $this->coefReal($it->coef_total_snapshot);

            $rows[] = [
                $controlNumero,
                $codigo,
                $inmuebleCabeza,
                $propietario,
                $coef3,
                $it->estado,
                $this->fmtHora($it->checked_in_at ?? null),
                $this->fmtHora($it->retirado_at ?? null),
                $this->fmtHora($it->reingreso_at ?? null),
            ];
        }

        return $rows;
    }
    private function coefReal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        // Ej: 0.2159 -> 0.22
        return round((float) $value, 2);
    }
    private function fmtHora($dt): string
    {
        if (!$dt)
            return '';

        try {
            // Si lo que viene está en UTC, lo convertimos a la TZ de la app
            return \Carbon\Carbon::parse($dt, 'UTC')
                ->setTimezone(config('app.timezone'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return (string) $dt;
        }
    }
}
class InformePoderesSheet implements FromArray, WithTitle, \Maatwebsite\Excel\Concerns\WithColumnFormatting
{
    public function __construct(public int $eventoId)
    {
    }

    public function title(): string
    {
        return 'Poderes';
    }

    public function columnFormats(): array
    {
        // D,E,F = coeficientes (numéricos con 2 decimales)
        return [
            'D' => '0.00', // coef propio
            'E' => '0.00', // coef por poderes
            'F' => '0.00', // coef total
        ];
    }

    public function array(): array
    {
        $rows = [];

        $rows[] = ['PODERES / REPRESENTACIONES'];
        $rows[] = ['Consolidado por "unidades de voto": solo cabezas que NO están representadas por otro inmueble.'];
        $rows[] = ['(Así el total no supera 100%)'];
        $rows[] = [''];

        $rows[] = [
            'Inmueble cabeza',
            'Propietario cabeza',
            '# Poderes',
            'Coef propio',
            'Coef poderes',
            'Coef total',
            'Estado',
            '# Control',
            'Código Control',
        ];

        // ✅ Inmuebles que están representados por otro (apoderados):
        // si un inmueble aparece como es_cabeza=0 en algún grupo, NO debe salir como cabeza independiente.
        $apoderadosIds = DB::table('representacion_miembros')
            ->where('evento_id', $this->eventoId)
            ->where('es_cabeza', 0)
            ->pluck('padron_id')
            ->unique()
            ->values()
            ->all();

        // Último registro válido por inmueble (para estado/control de la cabeza)
        $lastRcByInmueble = DB::table('registros_checkin')
            ->selectRaw('inmueble_base_id, MAX(id) as last_id')
            ->where('evento_id', $this->eventoId)
            ->whereIn('estado', ['CHECKED_IN', 'RETIRADO'])
            ->groupBy('inmueble_base_id');

        // Grupos (cabezas) del evento, PERO excluyendo cabezas que son apoderados de otro
        $grupos = DB::table('representacion_grupos as g')
            ->leftJoin('evento_padron as cabeza', 'cabeza.id', '=', 'g.cabeza_padron_id')
            ->leftJoinSub($lastRcByInmueble, 'lr', function ($join) {
                $join->on('lr.inmueble_base_id', '=', 'g.cabeza_padron_id');
            })
            ->leftJoin('registros_checkin as rc', 'rc.id', '=', 'lr.last_id')
            ->where('g.evento_id', $this->eventoId)
            ->when(!empty($apoderadosIds), function ($q) use ($apoderadosIds) {
                $q->whereNotIn('g.cabeza_padron_id', $apoderadosIds);
            })
            ->orderBy('cabeza.inmueble')
            ->get([
                'g.id as grupo_id',
                'g.cabeza_padron_id',

                'g.control_numero as g_control_numero',
                'g.control_serial as g_control_serial',

                'cabeza.inmueble as cabeza_inmueble',
                'cabeza.propietario as cabeza_propietario',
                'cabeza.coeficiente as cabeza_coef',

                'rc.estado as rc_estado',
                'rc.control_numero_snapshot',
                'rc.control_serial_snapshot',
            ]);

        // Precargamos miembros por grupo (para sumar coef de apoderados)
        $miembros = DB::table('representacion_miembros as rm')
            ->join('evento_padron as ep', 'ep.id', '=', 'rm.padron_id')
            ->where('rm.evento_id', $this->eventoId)
            ->get([
                'rm.grupo_id',
                'rm.es_cabeza',
                'ep.coeficiente',
            ])
            ->groupBy('grupo_id');

        $totalCoefPropio = 0.0;
        $totalCoefPoderes = 0.0;
        $totalCoefFinal = 0.0;

        foreach ($grupos as $g) {
            $grupoMiembros = $miembros->get($g->grupo_id, collect());

            // poderes = miembros que NO son cabeza
            $poderesCount = $grupoMiembros->where('es_cabeza', 0)->count();

            // coef propio = coef de la cabeza (evento_padron)
            $coefPropioRaw = (float) ($g->cabeza_coef ?? 0);

            $coefPoderesRaw = 0.0;
            foreach ($grupoMiembros as $m) {
                if ((int) $m->es_cabeza === 0) {
                    $coefPoderesRaw += (float) ($m->coeficiente ?? 0);
                }
            }

            // Para mostrar en fila:
            $coefPropio = $this->coefReal($coefPropioRaw);
            $coefPoderes = $this->coefReal($coefPoderesRaw);
            $coefTotal = $this->coefReal($coefPropioRaw + $coefPoderesRaw);

            // estado/control: preferimos snapshots de rc; fallback a campos del grupo
            $estado = $g->rc_estado ?? '';
            $controlNumero = $g->control_numero_snapshot ?? ($g->g_control_numero ?? '');
            $controlSerial = $g->control_serial_snapshot ?? ($g->g_control_serial ?? '');

            $rows[] = [
                $g->cabeza_inmueble ?? '',
                $g->cabeza_propietario ?? '',
                $poderesCount,
                $coefPropio,
                $coefPoderes,
                $coefTotal,
                $estado,
                $controlNumero,
                $controlSerial,
            ];

            $totalCoefPropio += $coefPropioRaw;
            $totalCoefPoderes += $coefPoderesRaw;
            $totalCoefFinal += ($coefPropioRaw + $coefPoderesRaw);
        }

        // Fila total visual (ahora sí debería cuadrar <= 100)
        $rows[] = [''];
        $rows[] = [
            'TOTAL',
            '',
            '',
            round($totalCoefPropio, 2),
            round($totalCoefPoderes, 2),
            round($totalCoefFinal, 2),
            '',
            '',
            '',
        ];

        return $rows;
    }

    private function coefReal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) $value, 2);
    }
}

class InformePoderesDetalleSheet implements FromArray, WithTitle, \Maatwebsite\Excel\Concerns\WithColumnFormatting, \Maatwebsite\Excel\Concerns\WithStyles
{
    public function __construct(public int $eventoId)
    {
    }

    public function title(): string
    {
        return 'Poderes Detalle';
    }

    public function columnFormats(): array
    {
        return [
            'A' => '@',    // Cabeza (inmueble) como texto
            'D' => '@',    // Apoderado (inmueble) como texto
            'C' => '0.00', // Coef cabeza
            'F' => '0.00', // Coef apoderado
        ];
    }

    private array $blocks = [];


    public function array(): array
    {
        $rows = [];

        $rows[] = ['PODERES · DETALLE POR CABEZA'];
        $rows[] = ['Listado de apoderados agrupados por inmueble cabeza.'];
        $rows[] = [''];

        // Encabezado
        $rows[] = ['Cabeza (inmueble)', 'Propietario cabeza', 'Coef cabeza', 'Apoderado (inmueble)', 'Apoderado (propietario)', 'Coef apoderado'];

        // Traemos miembros + datos del padrón
        $items = DB::table('representacion_miembros as rm')
            ->join('representacion_grupos as g', 'g.id', '=', 'rm.grupo_id')
            ->join('evento_padron as cabeza', 'cabeza.id', '=', 'g.cabeza_padron_id')
            ->join('evento_padron as ap', 'ap.id', '=', 'rm.padron_id')
            ->where('rm.evento_id', $this->eventoId)
            ->where('rm.es_cabeza', 0) // solo apoderados
            ->orderBy('cabeza.inmueble')
            ->orderBy('ap.inmueble')
            ->get([
                'g.id as grupo_id',
                'cabeza.inmueble as cabeza_inmueble',
                'cabeza.propietario as cabeza_propietario',
                'cabeza.coeficiente as cabeza_coef',
                'ap.inmueble as ap_inmueble',
                'ap.propietario as ap_propietario',
                'ap.coeficiente as ap_coef',
            ]);

        // Agrupar por cabeza (inmueble)
        $byCabeza = $items->groupBy('cabeza_inmueble');

        foreach ($byCabeza as $cabezaInmueble => $list) {
            $blockStart = count($rows) + 1; // Excel rows son 1-based
            $propCabeza = $list->first()->cabeza_propietario ?? '';
            $coefCabeza = $this->coefReal($list->first()->cabeza_coef ?? 0);

            // Fila “título” del grupo (la dejamos simple por ahora)
            $rows[] = ['CABEZA', $cabezaInmueble, $coefCabeza, $propCabeza, '', ''];
            $rows[] = ['', '', '', '', '', ''];

            $sumAp = 0.0;

            foreach ($list as $it) {
                $coef = $this->coefReal($it->ap_coef);
                $sumAp += (float) ($it->ap_coef ?? 0);

                $rows[] = [
                    (string) $cabezaInmueble,
                    $propCabeza,
                    $coefCabeza,
                    (string) ($it->ap_inmueble ?? ''),
                    $it->ap_propietario ?? '',
                    $coef,
                ];
            }

            // Subtotal visual por cabeza
            $rows[] = ['', '', '', '', 'Subtotal apoderados:', $this->coefReal($sumAp)];
            $rows[] = ['']; // línea separadora
            $blockEnd = count($rows); // hasta la fila del subtotal
            $this->blocks[] = [$blockStart, $blockEnd];

            $rows[] = ['']; // línea separadora (fuera del borde)
        }

        return $rows;
    }

    private function coefReal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) $value, 2);
    }
    public function styles(Worksheet $sheet)
    {
        // Título principal
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // Encabezados de tabla (fila 4)
        $sheet->getStyle('A4:F4')->getFont()->setBold(true);
        $sheet->getStyle('A4:F4')->getBorders()->getBottom()->setBorderStyle('thin');

        // Ajustes visuales generales
        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $sheet->freezePane('A5');
        }

        // Estilos por bloque
        foreach ($this->blocks as [$start, $end]) {
            // Borde suave alrededor del bloque (A..F)
            $sheet->getStyle("A{$start}:F{$end}")
                ->getBorders()
                ->getOutline()
                ->setBorderStyle('thin');

            // Fila “CABEZA” (es la primera fila del bloque + 1 si tu bloque inicia justo donde agregas la fila CABEZA)
            // En tu armado actual, la fila CABEZA es la primera fila del bloque.
            $sheet->getStyle("A{$start}:F{$start}")
                ->getFont()
                ->setBold(true);

            // Fondo sutil fila CABEZA (gris claro)
            $sheet->getStyle("A{$start}:F{$start}")
                ->getFill()
                ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                ->getStartColor()
                ->setARGB('FFF2F2F2');

            // Subtotal (buscamos la fila que contiene "Subtotal apoderados:")
            // Como está siempre hacia el final, recorremos el rango del bloque
            for ($r = $start; $r <= $end; $r++) {
                $val = $sheet->getCell("E{$r}")->getValue();
                if ($val === 'Subtotal apoderados:') {
                    $sheet->getStyle("E{$r}:F{$r}")->getFont()->setBold(true);
                    $sheet->getStyle("E{$r}:F{$r}")
                        ->getBorders()
                        ->getTop()
                        ->setBorderStyle('thin');
                    break;
                }
            }
        }

        return [];
    }
}
