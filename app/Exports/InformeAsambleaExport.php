<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\FromArray;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
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
            new InformeAusentesSheet($this->eventoId),
            new InformePoderesSheet($this->eventoId),
            new InformePoderesDetalleSheet($this->eventoId),
        ];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// RESUMEN
// Filas: 1=título  2=Evento  3=Generado  4=vacía  5=RESUMEN GENERAL
//        6-9=datos  10=total
// ─────────────────────────────────────────────────────────────────────────────
class InformeResumenSheet implements FromArray, WithTitle, \Maatwebsite\Excel\Concerns\WithColumnFormatting, \Maatwebsite\Excel\Concerns\WithStyles
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
        $tq = DB::table('eventos')->where('id', $this->eventoId)->value('tipo_quorum') ?? 'coeficiente';
        return $tq === 'nominal'
            ? ['B' => '0']
            : ['B' => '0.00'];
    }

    public function array(): array
    {
        $evento     = DB::table('eventos')->where('id', $this->eventoId)->first();
        $titulo     = $evento->titulo ?? 'Evento';
        $fecha      = now()->format('Y-m-d H:i');
        $tipoQuorum = $evento->tipo_quorum ?? 'coeficiente';

        $controlesActivos = DB::table('registros_checkin')
            ->where('evento_id', $this->eventoId)
            ->whereIn('estado', ['CHECKED_IN', 'RETIRADO'])
            ->whereNotNull('control_id')
            ->distinct()
            ->count('control_id');

        if ($tipoQuorum === 'nominal') {
            $votosPresente = (int) DB::table('registros_checkin')
                ->where('evento_id', $this->eventoId)
                ->where('estado', 'CHECKED_IN')
                ->whereNotNull('persona_id')
                ->sum('coef_total_snapshot');

            $votosRetirado = (int) DB::table('registros_checkin')
                ->where('evento_id', $this->eventoId)
                ->where('estado', 'RETIRADO')
                ->whereNotNull('persona_id')
                ->sum('coef_total_snapshot');

            $personasConRegistro = (int) DB::table('registros_checkin')
                ->where('evento_id', $this->eventoId)
                ->whereNotNull('persona_id')
                ->whereIn('estado', ['CHECKED_IN', 'RETIRADO'])
                ->count();

            $personasTotal = (int) DB::table('evento_personas')
                ->where('evento_id', $this->eventoId)
                ->count();

            $personasNoAsistio = $personasTotal - $personasConRegistro;

            return [
                ['COEFIX - INFORME DE ASAMBLEA'],
                ['Evento:', $titulo],
                ['Generado:', $fecha],
                [''],
                ['RESUMEN GENERAL (MODO NOMINAL)'],
                ['Controles activos:', $controlesActivos],
                ['Votos presentes (CHECKED_IN):', $votosPresente],
                ['Votos retirados (RETIRADO):', $votosRetirado],
                ['Personas no asistieron:', $personasNoAsistio],
                ['Total personas en padrón nominal:', $personasTotal],
            ];
        }

        // ── MODO COEFICIENTE ─────────────────────────────────────────
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
            ->get(['rc.estado', 'rc.coef_total_snapshot', 'rc.control_id']);

        $coefPresente = 0.0;
        $coefRetirado = 0.0;

        foreach ($items as $it) {
            $coef = $this->coefReal($it->coef_total_snapshot);
            if ($it->estado === 'CHECKED_IN') {
                $coefPresente += $coef;
            }
            if ($it->estado === 'RETIRADO') {
                $coefRetirado += $coef;
            }
        }

        $coefPresente = round($coefPresente, 2);
        $coefRetirado = round($coefRetirado, 2);

        $coefNoAsistio = (float) DB::table('evento_padron as ep')
            ->where('ep.evento_id', $this->eventoId)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('registros_checkin as rc')
                    ->whereColumn('rc.inmueble_base_id', 'ep.id')
                    ->where('rc.evento_id', $this->eventoId)
                    ->whereIn('rc.estado', ['CHECKED_IN', 'RETIRADO']);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('representacion_miembros as rm')
                    ->whereColumn('rm.padron_id', 'ep.id')
                    ->where('rm.evento_id', $this->eventoId)
                    ->where('rm.es_cabeza', 0);
            })
            ->sum('ep.coeficiente');

        $coefNoAsistio = round($coefNoAsistio, 2);
        $coefTotal     = round($coefPresente + $coefRetirado + $coefNoAsistio, 2);

        return [
            ['COEFIX - INFORME DE ASAMBLEA'],
            ['Evento:', $titulo],
            ['Generado:', $fecha],
            [''],
            ['RESUMEN GENERAL'],
            ['Controles activos:', $controlesActivos],
            ['Coeficiente presente (CHECKED_IN):', $coefPresente],
            ['Coeficiente retirado (RETIRADO):', $coefRetirado],
            ['Coeficiente no asistió:', $coefNoAsistio],
            ['Coeficiente total (presente + retirado + no asistió):', $coefTotal],
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // B6 = valor de "Controles activos" — mostrar como entero
        $sheet->getStyle('B6')->getNumberFormat()->setFormatCode('0');

        // Título (A1:B1) — centrado
        $sheet->mergeCells('A1:B1');
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F3864');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        // Encabezado de sección (fila 5)
        $sheet->getStyle('A5:B5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2E75B6');
        $sheet->getStyle('A5:B5')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A5:B5')->getAlignment()->setWrapText(true);

        // Filas de datos (6–10)
        $sheet->getStyle('A6:B10')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFF6FF');
        $sheet->getStyle('A6:B10')->getBorders()->getAllBorders()->setBorderStyle('thin');
        $sheet->getStyle('A6:B10')->getAlignment()->setWrapText(true);

        // Fila total (10)
        $sheet->getStyle('A10:B10')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBF7');
        $sheet->getStyle('A10:B10')->getFont()->setBold(true);

        // Anchos de columna
        $sheet->getColumnDimension('A')->setWidth(52);
        $sheet->getColumnDimension('B')->setWidth(14);

        return [];
    }

    private function coefReal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float) $value;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// QUÓRUM
// Filas: 1=título  2=vacía  3-5=resumen  6=vacía
//        7=PRESENTES  8=encab.tabla  9+=datos  ...  RETIRADOS (dinámico)
// ─────────────────────────────────────────────────────────────────────────────
class InformeQuorumSheet implements FromArray, WithTitle, \Maatwebsite\Excel\Concerns\WithColumnFormatting, \Maatwebsite\Excel\Concerns\WithStyles
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
        $tq = DB::table('eventos')->where('id', $this->eventoId)->value('tipo_quorum') ?? 'coeficiente';
        return $tq === 'nominal'
            ? ['B' => '0', 'C' => '0']
            : ['B' => '0.00', 'C' => '0.00'];
    }

    public function array(): array
    {
        $tipoQuorum = DB::table('eventos')->where('id', $this->eventoId)->value('tipo_quorum') ?? 'coeficiente';

        $rows = [];

        if ($tipoQuorum === 'nominal') {
            $items = DB::table('registros_checkin as rc')
                ->leftJoin('evento_personas as ep', 'ep.id', '=', 'rc.persona_id')
                ->where('rc.evento_id', $this->eventoId)
                ->whereNotNull('rc.persona_id')
                ->whereIn('rc.estado', ['CHECKED_IN', 'RETIRADO'])
                ->orderBy('rc.control_numero_snapshot')
                ->get(['rc.estado', 'rc.coef_total_snapshot', 'ep.cedula', 'ep.nombre']);

            $presentes    = [];
            $retirados    = [];
            $votosPresente = 0;
            $votosRetirado = 0;

            foreach ($items as $it) {
                $cedula = $it->cedula ?? '—';
                $nombre = $it->nombre ?? '—';
                $votos  = (int) ($it->coef_total_snapshot ?? 1);

                if ($it->estado === 'CHECKED_IN') {
                    $votosPresente += $votos;
                    $presentes[] = [$cedula, $nombre, $votos];
                } else {
                    $votosRetirado += $votos;
                    $retirados[] = [$cedula, $nombre, $votos];
                }
            }

            $votosTotal = $votosPresente + $votosRetirado;

            $rows[] = ['QUÓRUM (SNAPSHOT ACTUAL - MODO NOMINAL)'];
            $rows[] = [''];
            $rows[] = ['Votos presentes (CHECKED_IN):', $votosPresente];
            $rows[] = ['Votos retirados (RETIRADO):', $votosRetirado];
            $rows[] = ['Votos totales (presente + retirado):', $votosTotal];
            $rows[] = [''];
            $rows[] = ['PRESENTES'];
            $rows[] = ['Cédula', 'Nombre', 'Votos'];
            foreach ($presentes as $r) {
                $rows[] = $r;
            }
            $rows[] = [''];
            $rows[] = ['RETIRADOS'];
            $rows[] = ['Cédula', 'Nombre', 'Votos'];
            foreach ($retirados as $r) {
                $rows[] = $r;
            }

            return $rows;
        }

        // ── MODO COEFICIENTE ─────────────────────────────────────────
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
                'ep.coeficiente as coef_padron',
            ]);

        $presentes    = [];
        $retirados    = [];
        $coefPresente = 0.0;
        $coefRetirado = 0.0;

        foreach ($items as $it) {
            $inmueble    = $it->cabeza_inmueble_snapshot ?: ($it->inmueble_padron ?? '');
            $propietario = $it->propietario_padron ?? '';
            $coefRaw     = (float) ($it->coef_total_snapshot ?? 0);

            if ($it->estado === 'CHECKED_IN') {
                $coefPresente += $coefRaw;
                $presentes[] = [$inmueble, $propietario, $coefRaw];
            }
            if ($it->estado === 'RETIRADO') {
                $coefRetirado += $coefRaw;
                $retirados[] = [$inmueble, $propietario, $coefRaw];
            }
        }

        $coefPresente = round($coefPresente, 2);
        $coefRetirado = round($coefRetirado, 2);
        $coefTotal    = round($coefPresente + $coefRetirado, 2);

        $rows[] = ['QUÓRUM (SNAPSHOT ACTUAL)'];
        $rows[] = [''];
        $rows[] = ['Coeficiente presente (CHECKED_IN):', $coefPresente];
        $rows[] = ['Coeficiente retirado (RETIRADO):', $coefRetirado];
        $rows[] = ['Coeficiente total (presente + retirado):', $coefTotal];
        $rows[] = [''];
        $rows[] = ['PRESENTES'];
        $rows[] = ['Inmueble cabeza', 'Propietario', 'Coef'];
        foreach ($presentes as $r) {
            $rows[] = $r;
        }
        $rows[] = [''];
        $rows[] = ['RETIRADOS'];
        $rows[] = ['Inmueble cabeza', 'Propietario', 'Coef'];
        foreach ($retirados as $r) {
            $rows[] = $r;
        }

        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();

        // Título (A1:C1) — centrado
        $sheet->mergeCells('A1:C1');
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F3864');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        // Filas de resumen (3–5)
        $sheet->getStyle('A3:B5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFEFF6FF');
        $sheet->getStyle('A3:B5')->getBorders()->getAllBorders()->setBorderStyle('thin');
        $sheet->getStyle('A3:B5')->getAlignment()->setWrapText(true);

        // Encabezado sección PRESENTES (fila 7 — fija)
        $sheet->getStyle('A7:C7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2E75B6');
        $sheet->getStyle('A7:C7')->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');

        // Encabezado tabla PRESENTES (fila 8 — fija)
        $sheet->getRowDimension(8)->setRowHeight(18);
        $sheet->getStyle('A8:C8')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6E4F0');
        $sheet->getStyle('A8:C8')->getFont()->setBold(true);
        $sheet->getStyle('A8:C8')->getBorders()->getAllBorders()->setBorderStyle('thin');
        $sheet->getStyle('A8:C8')->getAlignment()->setWrapText(true);

        // Buscar fila RETIRADOS dinámicamente
        $retiradosRow = null;
        for ($r = 9; $r <= $lastRow; $r++) {
            if ($sheet->getCell("A{$r}")->getValue() === 'RETIRADOS') {
                $retiradosRow = $r;
                break;
            }
        }

        // Datos PRESENTES (fila 9 hasta antes del separador vacío)
        if ($retiradosRow && $retiradosRow > 10) {
            $presentesDataEnd = $retiradosRow - 2;
            if ($presentesDataEnd >= 9) {
                $sheet->getStyle("A9:C{$presentesDataEnd}")->getBorders()->getAllBorders()->setBorderStyle('thin');
                $sheet->getStyle("A9:C{$presentesDataEnd}")->getAlignment()->setWrapText(true);
            }
        }

        // Sección RETIRADOS (dinámica)
        if ($retiradosRow) {
            $sheet->getStyle("A{$retiradosRow}:C{$retiradosRow}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF2E75B6');
            $sheet->getStyle("A{$retiradosRow}:C{$retiradosRow}")
                ->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');

            $retHeaderRow = $retiradosRow + 1;
            $sheet->getRowDimension($retHeaderRow)->setRowHeight(18);
            $sheet->getStyle("A{$retHeaderRow}:C{$retHeaderRow}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6E4F0');
            $sheet->getStyle("A{$retHeaderRow}:C{$retHeaderRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$retHeaderRow}:C{$retHeaderRow}")->getBorders()->getAllBorders()->setBorderStyle('thin');
            $sheet->getStyle("A{$retHeaderRow}:C{$retHeaderRow}")->getAlignment()->setWrapText(true);

            $retDataStart = $retHeaderRow + 1;
            if ($retDataStart <= $lastRow) {
                $sheet->getStyle("A{$retDataStart}:C{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle('thin');
                $sheet->getStyle("A{$retDataStart}:C{$lastRow}")->getAlignment()->setWrapText(true);
            }
        }

        // Anchos de columna
        $sheet->getColumnDimension('A')->setWidth(22);
        $sheet->getColumnDimension('B')->setWidth(30);
        $sheet->getColumnDimension('C')->setWidth(12);

        return [];
    }

    private function coefReal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) $value, 2);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// ASISTENCIA
// Filas: 1=título  2=vacía  3=encab.tabla  4+=datos
// ─────────────────────────────────────────────────────────────────────────────
class InformeAsistenciaSheet implements FromArray, WithTitle, \Maatwebsite\Excel\Concerns\WithColumnFormatting, \Maatwebsite\Excel\Concerns\WithStyles
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
        $tq = DB::table('eventos')->where('id', $this->eventoId)->value('tipo_quorum') ?? 'coeficiente';
        return $tq === 'nominal'
            ? ['H' => '0']
            : ['H' => '0.00'];
    }

    public function array(): array
    {
        $tipoQuorum = DB::table('eventos')->where('id', $this->eventoId)->value('tipo_quorum') ?? 'coeficiente';

        $rows   = [];
        $rows[] = ['ASISTENCIA (BASE TURNING)'];
        $rows[] = [''];

        if ($tipoQuorum === 'nominal') {
            $rows[] = ['# Control', 'Código', 'Cédula', 'Nombre', '', 'Teléfono', 'Correo', 'Votos', 'Estado', 'Hora check-in', 'Hora retiro', 'Hora reingreso'];

            $items = DB::table('registros_checkin as rc')
                ->leftJoin('evento_personas as ep', 'ep.id', '=', 'rc.persona_id')
                ->leftJoin('controles as c', function ($join) {
                    $join->on('c.evento_id', '=', 'rc.evento_id')
                        ->where(function ($q) {
                            $q->whereColumn('c.id', 'rc.control_id')
                                ->orWhereColumn('c.numero', 'rc.control_numero_snapshot');
                        });
                })
                ->where('rc.evento_id', $this->eventoId)
                ->whereNotNull('rc.persona_id')
                ->whereIn('rc.estado', ['CHECKED_IN', 'RETIRADO'])
                ->orderBy('rc.control_numero_snapshot')
                ->get([
                    'rc.estado',
                    'rc.control_numero_snapshot',
                    'rc.control_serial_snapshot',
                    'rc.coef_total_snapshot',
                    'rc.asistente_telefono',
                    'rc.asistente_correo',
                    'rc.checked_in_at',
                    'rc.retirado_at',
                    'rc.reingreso_at',
                    'ep.cedula',
                    'ep.nombre',
                    'c.serial as control_serial_db',
                ]);

            foreach ($items as $it) {
                $rows[] = [
                    $it->control_numero_snapshot ?? '',
                    $it->control_serial_snapshot ?? ($it->control_serial_db ?? ''),
                    $it->cedula ?? '',
                    $it->nombre ?? '',
                    '',
                    $it->asistente_telefono ?? '',
                    $it->asistente_correo ?? '',
                    (int) ($it->coef_total_snapshot ?? 1),
                    $it->estado,
                    $this->fmtHora($it->checked_in_at ?? null),
                    $this->fmtHora($it->retirado_at ?? null),
                    $this->fmtHora($it->reingreso_at ?? null),
                ];
            }

            return $rows;
        }

        // ── MODO COEFICIENTE ─────────────────────────────────────────
        $rows[] = ['# Control', 'Código', 'Inmueble cabeza', 'Propietario', 'Asistente', 'Teléfono', 'Correo', 'Coef', 'Estado', 'Hora check-in', 'Hora retiro', 'Hora reingreso'];

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
                'rc.asistente_nombre',
                'rc.asistente_telefono',
                'rc.asistente_correo',
                'rc.checked_in_at',
                'rc.retirado_at',
                'rc.reingreso_at',
                'ep.inmueble as inmueble_padron',
                'ep.propietario as propietario_padron',
                'ep.coeficiente as coef_padron',
                'c.serial as control_serial_db',
            ]);

        foreach ($items as $it) {
            $rows[] = [
                $it->control_numero_snapshot ?? '',
                $it->control_serial_snapshot ?? ($it->control_serial_db ?? ''),
                $it->cabeza_inmueble_snapshot ?: ($it->inmueble_padron ?? ''),
                $it->propietario_padron ?? '',
                $it->asistente_nombre ?? '',
                $it->asistente_telefono ?? '',
                $it->asistente_correo ?? '',
                (float) ($it->coef_total_snapshot ?? 0),
                $it->estado,
                $this->fmtHora($it->checked_in_at ?? null),
                $this->fmtHora($it->retirado_at ?? null),
                $this->fmtHora($it->reingreso_at ?? null),
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();

        // Título (A1:L1) — centrado
        $sheet->mergeCells('A1:L1');
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F3864');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        // Encabezado de tabla (fila 3)
        $sheet->getRowDimension(3)->setRowHeight(18);
        $sheet->getStyle('A3:L3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6E4F0');
        $sheet->getStyle('A3:L3')->getFont()->setBold(true);
        $sheet->getStyle('A3:L3')->getBorders()->getAllBorders()->setBorderStyle('thin');
        $sheet->getStyle('A3:L3')->getAlignment()->setWrapText(true);

        // Filas de datos (4+)
        if ($lastRow >= 4) {
            $sheet->getStyle("A4:L{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle('thin');
            $sheet->getStyle("A4:L{$lastRow}")->getAlignment()->setWrapText(true);
        }

        // Inmovilizar desde fila 4
        $sheet->freezePane('A4');

        // Anchos de columna
        $sheet->getColumnDimension('A')->setWidth(10);
        $sheet->getColumnDimension('B')->setWidth(12);
        $sheet->getColumnDimension('C')->setWidth(18);
        $sheet->getColumnDimension('D')->setWidth(25);
        $sheet->getColumnDimension('E')->setWidth(25);
        $sheet->getColumnDimension('F')->setWidth(15);
        $sheet->getColumnDimension('G')->setWidth(28);
        $sheet->getColumnDimension('H')->setWidth(10);
        $sheet->getColumnDimension('I')->setWidth(12);
        $sheet->getColumnDimension('J')->setWidth(22);
        $sheet->getColumnDimension('K')->setWidth(22);
        $sheet->getColumnDimension('L')->setWidth(22);

        return [];
    }

    private function coefReal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) $value, 2);
    }

    private function fmtHora($dt): string
    {
        if (!$dt) {
            return '';
        }

        try {
            return \Carbon\Carbon::parse($dt)
                ->format('Y-m-d h:i:s A');
        } catch (\Throwable $e) {
            return (string) $dt;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PODERES
// Filas: 1=título  2=vacía  3=encab.tabla  4+=datos  última-1=vacía  última=TOTAL
// ─────────────────────────────────────────────────────────────────────────────
class InformePoderesSheet implements FromArray, WithTitle, \Maatwebsite\Excel\Concerns\WithColumnFormatting, \Maatwebsite\Excel\Concerns\WithStyles
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
        $tq = DB::table('eventos')->where('id', $this->eventoId)->value('tipo_quorum') ?? 'coeficiente';
        return $tq === 'nominal'
            ? ['D' => '0', 'E' => '0', 'F' => '0']
            : ['D' => '0.00', 'E' => '0.00', 'F' => '0.00'];
    }

    public function array(): array
    {
        $tipoQuorum = DB::table('eventos')->where('id', $this->eventoId)->value('tipo_quorum') ?? 'coeficiente';

        $rows   = [];
        $rows[] = ['PODERES / REPRESENTACIONES'];
        $rows[] = [''];

        if ($tipoQuorum === 'nominal') {
            $rows[] = ['Nombre cabeza', 'Cédula cabeza', '# Poderes', 'Votos propios (1)', 'Votos poderes', 'Votos total', 'Estado', '# Control', 'Código Control'];

            $poderesPersonaIds = DB::table('representacion_miembros_nominal')
                ->where('evento_id', $this->eventoId)
                ->where('es_cabeza', 0)
                ->pluck('persona_id')
                ->unique()
                ->values()
                ->all();

            $lastRcByPersona = DB::table('registros_checkin')
                ->selectRaw('persona_id, MAX(id) as last_id')
                ->where('evento_id', $this->eventoId)
                ->whereNotNull('persona_id')
                ->whereIn('estado', ['CHECKED_IN', 'RETIRADO'])
                ->groupBy('persona_id');

            $grupos = DB::table('representacion_grupos_nominal as g')
                ->leftJoin('evento_personas as cabeza', 'cabeza.id', '=', 'g.cabeza_persona_id')
                ->leftJoinSub($lastRcByPersona, 'lr', function ($join) {
                    $join->on('lr.persona_id', '=', 'g.cabeza_persona_id');
                })
                ->leftJoin('registros_checkin as rc', 'rc.id', '=', 'lr.last_id')
                ->where('g.evento_id', $this->eventoId)
                ->when(!empty($poderesPersonaIds), function ($q) use ($poderesPersonaIds) {
                    $q->whereNotIn('g.cabeza_persona_id', $poderesPersonaIds);
                })
                ->orderBy('cabeza.nombre')
                ->get([
                    'g.id as grupo_id',
                    'cabeza.nombre as cabeza_nombre',
                    'cabeza.cedula as cabeza_cedula',
                    'rc.estado as rc_estado',
                    'rc.control_numero_snapshot',
                    'rc.control_serial_snapshot',
                ]);

            $miembros = DB::table('representacion_miembros_nominal as rm')
                ->where('rm.evento_id', $this->eventoId)
                ->get(['rm.grupo_id', 'rm.es_cabeza'])
                ->groupBy('grupo_id');

            $totalVotosPropio  = 0;
            $totalVotosPoderes = 0;
            $totalVotosTotal   = 0;

            foreach ($grupos as $g) {
                $grupoMiembros = $miembros->get($g->grupo_id, collect());
                $poderesCount  = $grupoMiembros->where('es_cabeza', 0)->count();
                $votosTotal    = 1 + $poderesCount;

                $rows[] = [
                    $g->cabeza_nombre ?? '',
                    $g->cabeza_cedula ?? '',
                    $poderesCount,
                    1,
                    $poderesCount,
                    $votosTotal,
                    $g->rc_estado ?? '',
                    $g->control_numero_snapshot ?? '',
                    $g->control_serial_snapshot ?? '',
                ];

                $totalVotosPropio++;
                $totalVotosPoderes += $poderesCount;
                $totalVotosTotal   += $votosTotal;
            }

            $rows[] = [''];
            $rows[] = ['TOTAL', '', '', $totalVotosPropio, $totalVotosPoderes, $totalVotosTotal, '', '', ''];
            return $rows;
        }

        // ── MODO COEFICIENTE ─────────────────────────────────────────
        $rows[] = ['Inmueble cabeza', 'Propietario cabeza', '# Poderes', 'Coef propio', 'Coef poderes', 'Coef total', 'Estado', '# Control', 'Código Control'];

        $apoderadosIds = DB::table('representacion_miembros')
            ->where('evento_id', $this->eventoId)
            ->where('es_cabeza', 0)
            ->pluck('padron_id')
            ->unique()
            ->values()
            ->all();

        $lastRcByInmueble = DB::table('registros_checkin')
            ->selectRaw('inmueble_base_id, MAX(id) as last_id')
            ->where('evento_id', $this->eventoId)
            ->whereIn('estado', ['CHECKED_IN', 'RETIRADO'])
            ->groupBy('inmueble_base_id');

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

        $miembros = DB::table('representacion_miembros as rm')
            ->join('evento_padron as ep', 'ep.id', '=', 'rm.padron_id')
            ->where('rm.evento_id', $this->eventoId)
            ->get(['rm.grupo_id', 'rm.es_cabeza', 'ep.coeficiente'])
            ->groupBy('grupo_id');

        $totalCoefPropio  = 0.0;
        $totalCoefPoderes = 0.0;
        $totalCoefFinal   = 0.0;

        foreach ($grupos as $g) {
            $grupoMiembros  = $miembros->get($g->grupo_id, collect());
            $poderesCount   = $grupoMiembros->where('es_cabeza', 0)->count();
            $coefPropioRaw  = (float) ($g->cabeza_coef ?? 0);
            $coefPoderesRaw = 0.0;

            foreach ($grupoMiembros as $m) {
                if ((int) $m->es_cabeza === 0) {
                    $coefPoderesRaw += (float) ($m->coeficiente ?? 0);
                }
            }

            $rows[] = [
                $g->cabeza_inmueble ?? '',
                $g->cabeza_propietario ?? '',
                $poderesCount,
                $this->coefReal($coefPropioRaw),
                $this->coefReal($coefPoderesRaw),
                $this->coefReal($coefPropioRaw + $coefPoderesRaw),
                $g->rc_estado ?? '',
                $g->control_numero_snapshot ?? ($g->g_control_numero ?? ''),
                $g->control_serial_snapshot ?? ($g->g_control_serial ?? ''),
            ];

            $totalCoefPropio  += $coefPropioRaw;
            $totalCoefPoderes += $coefPoderesRaw;
            $totalCoefFinal   += ($coefPropioRaw + $coefPoderesRaw);
        }

        $rows[] = [''];
        $rows[] = ['TOTAL', '', '', round($totalCoefPropio, 2), round($totalCoefPoderes, 2), round($totalCoefFinal, 2), '', '', ''];

        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();

        // Título (A1:I1) — centrado
        $sheet->mergeCells('A1:I1');
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F3864');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        // Encabezado de tabla (fila 3)
        $sheet->getRowDimension(3)->setRowHeight(18);
        $sheet->getStyle('A3:I3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6E4F0');
        $sheet->getStyle('A3:I3')->getFont()->setBold(true);
        $sheet->getStyle('A3:I3')->getBorders()->getAllBorders()->setBorderStyle('thin');
        $sheet->getStyle('A3:I3')->getAlignment()->setWrapText(true);

        // Buscar fila TOTAL dinámicamente
        $totalRow = null;
        for ($r = 4; $r <= $lastRow; $r++) {
            if ($sheet->getCell("A{$r}")->getValue() === 'TOTAL') {
                $totalRow = $r;
                break;
            }
        }

        // Filas de datos (4 hasta antes de la fila vacía que precede a TOTAL)
        $dataEnd = $totalRow ? $totalRow - 2 : $lastRow;
        if ($dataEnd >= 4) {
            $sheet->getStyle("A4:I{$dataEnd}")->getBorders()->getAllBorders()->setBorderStyle('thin');
            $sheet->getStyle("A4:I{$dataEnd}")->getAlignment()->setWrapText(true);
        }

        // Fila TOTAL
        if ($totalRow) {
            $sheet->getStyle("A{$totalRow}:I{$totalRow}")
                ->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFDDEBF7');
            $sheet->getStyle("A{$totalRow}:I{$totalRow}")->getFont()->setBold(true);
            $sheet->getStyle("A{$totalRow}:I{$totalRow}")->getBorders()->getAllBorders()->setBorderStyle('thin');
        }

        // Inmovilizar desde fila 4
        $sheet->freezePane('A4');

        // Anchos de columna
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(10);
        $sheet->getColumnDimension('D')->setWidth(12);
        $sheet->getColumnDimension('E')->setWidth(12);
        $sheet->getColumnDimension('F')->setWidth(12);
        $sheet->getColumnDimension('G')->setWidth(12);
        $sheet->getColumnDimension('H')->setWidth(10);
        $sheet->getColumnDimension('I')->setWidth(14);

        return [];
    }

    private function coefReal($value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return round((float) $value, 2);
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// PODERES DETALLE
// Filas: 1=título  2=vacía  3=encab.tabla  4+=bloques dinámicos
// ─────────────────────────────────────────────────────────────────────────────
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
        $tq = DB::table('eventos')->where('id', $this->eventoId)->value('tipo_quorum') ?? 'coeficiente';
        return $tq === 'nominal'
            ? ['A' => '@', 'D' => '@', 'C' => '0', 'F' => '0']
            : ['A' => '@', 'D' => '@', 'C' => '0.00', 'F' => '0.00'];
    }

    private array $blocks = [];

    public function array(): array
    {
        $tipoQuorum = DB::table('eventos')->where('id', $this->eventoId)->value('tipo_quorum') ?? 'coeficiente';

        $rows = [];

        if ($tipoQuorum === 'nominal') {
            $rows[] = ['PODERES · DETALLE POR CABEZA (MODO NOMINAL)'];
            $rows[] = [''];
            $rows[] = ['Cabeza (nombre)', 'Cédula cabeza', '# Poderes', 'Apoderado (nombre)', 'Apoderado (cédula)', ''];

            $items = DB::table('representacion_miembros_nominal as rm')
                ->join('representacion_grupos_nominal as g', 'g.id', '=', 'rm.grupo_id')
                ->join('evento_personas as cabeza', 'cabeza.id', '=', 'g.cabeza_persona_id')
                ->join('evento_personas as ap', 'ap.id', '=', 'rm.persona_id')
                ->where('rm.evento_id', $this->eventoId)
                ->where('rm.es_cabeza', 0)
                ->orderBy('cabeza.nombre')
                ->orderBy('ap.nombre')
                ->get([
                    'cabeza.nombre as cabeza_nombre',
                    'cabeza.cedula as cabeza_cedula',
                    'ap.nombre as ap_nombre',
                    'ap.cedula as ap_cedula',
                ]);

            $byCabeza = $items->groupBy('cabeza_nombre');

            foreach ($byCabeza as $cabezaNombre => $list) {
                $blockStart   = count($rows) + 1;
                $cedulaCabeza = $list->first()->cabeza_cedula ?? '';
                $poderesCnt   = count($list);

                $rows[] = ['CABEZA', $cabezaNombre, $poderesCnt, $cedulaCabeza, '', ''];
                $rows[] = ['', '', '', '', '', ''];

                foreach ($list as $it) {
                    $rows[] = [
                        (string) $cabezaNombre,
                        $cedulaCabeza,
                        '',
                        (string) ($it->ap_nombre ?? ''),
                        $it->ap_cedula ?? '',
                        '',
                    ];
                }

                $rows[] = ['', '', '', '', 'Subtotal apoderados:', $poderesCnt];
                $rows[] = [''];
                $blockEnd = count($rows);
                $this->blocks[] = [$blockStart, $blockEnd];
                $rows[] = [''];
            }

            return $rows;
        }

        // ── MODO COEFICIENTE ─────────────────────────────────────────
        $rows[] = ['PODERES · DETALLE POR CABEZA'];
        $rows[] = [''];
        $rows[] = ['Cabeza (inmueble)', 'Propietario cabeza', 'Coef cabeza', 'Apoderado (inmueble)', 'Apoderado (propietario)', 'Coef apoderado'];

        $items = DB::table('representacion_miembros as rm')
            ->join('representacion_grupos as g', 'g.id', '=', 'rm.grupo_id')
            ->join('evento_padron as cabeza', 'cabeza.id', '=', 'g.cabeza_padron_id')
            ->join('evento_padron as ap', 'ap.id', '=', 'rm.padron_id')
            ->where('rm.evento_id', $this->eventoId)
            ->where('rm.es_cabeza', 0)
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

        $byCabeza = $items->groupBy('cabeza_inmueble');

        foreach ($byCabeza as $cabezaInmueble => $list) {
            $blockStart = count($rows) + 1;
            $propCabeza = $list->first()->cabeza_propietario ?? '';
            $coefCabeza = $this->coefReal($list->first()->cabeza_coef ?? 0);

            $rows[] = ['CABEZA', $cabezaInmueble, $coefCabeza, $propCabeza, '', ''];
            $rows[] = ['', '', '', '', '', ''];

            $sumAp = 0.0;

            foreach ($list as $it) {
                $coef   = $this->coefReal($it->ap_coef);
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

            $rows[] = ['', '', '', '', 'Subtotal apoderados:', $this->coefReal($sumAp)];
            $rows[] = [''];
            $blockEnd = count($rows);
            $this->blocks[] = [$blockStart, $blockEnd];
            $rows[] = [''];
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
        $lastRow = $sheet->getHighestRow();

        // Título (A1:F1) — centrado
        $sheet->mergeCells('A1:F1');
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F3864');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        // Encabezado de tabla (fila 3)
        $sheet->getRowDimension(3)->setRowHeight(18);
        $sheet->getStyle('A3:F3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6E4F0');
        $sheet->getStyle('A3:F3')->getFont()->setBold(true);
        $sheet->getStyle('A3:F3')->getBorders()->getBottom()->setBorderStyle('thin');
        $sheet->getStyle('A3:F3')->getAlignment()->setWrapText(true);

        foreach (range('A', 'F') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
            $sheet->freezePane('A4');
        }

        foreach ($this->blocks as [$start, $end]) {
            $sheet->getStyle("A{$start}:F{$end}")
                ->getBorders()
                ->getOutline()
                ->setBorderStyle('thin');

            $sheet->getStyle("A{$start}:F{$start}")
                ->getFont()
                ->setBold(true);

            $sheet->getStyle("A{$start}:F{$start}")
                ->getFill()
                ->setFillType(Fill::FILL_SOLID)
                ->getStartColor()
                ->setARGB('FFBDD7EE');

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

        // wrapText en filas de datos
        if ($lastRow >= 4) {
            $sheet->getStyle("A4:F{$lastRow}")->getAlignment()->setWrapText(true);
        }

        return [];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// AUSENTES
// Filas: 1=título  2=vacía  3=encab.tabla  4+=datos  última=vacía
// ─────────────────────────────────────────────────────────────────────────────
class InformeAusentesSheet implements FromArray, WithTitle, \Maatwebsite\Excel\Concerns\WithColumnFormatting, \Maatwebsite\Excel\Concerns\WithStyles
{
    public function __construct(public int $eventoId)
    {
    }

    public function title(): string
    {
        return 'Ausentes';
    }

    public function columnFormats(): array
    {
        return [
            'C' => '0.00',
        ];
    }

    public function array(): array
    {
        $tipoQuorum = DB::table('eventos')->where('id', $this->eventoId)->value('tipo_quorum') ?? 'coeficiente';

        $rows = [];

        if ($tipoQuorum === 'nominal') {
            $rows[] = ['AUSENTES (NO REGISTRADOS - MODO NOMINAL)'];
            $rows[] = [''];
            $rows[] = ['Cédula', 'Nombre', 'Teléfono', 'Correo', '', ''];

            $ausentes = DB::table('evento_personas as ep')
                ->where('ep.evento_id', $this->eventoId)
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('registros_checkin as rc')
                        ->whereColumn('rc.persona_id', 'ep.id')
                        ->where('rc.evento_id', $this->eventoId)
                        ->whereIn('rc.estado', ['CHECKED_IN', 'RETIRADO']);
                })
                ->whereNotExists(function ($q) {
                    $q->select(DB::raw(1))
                        ->from('representacion_miembros_nominal as rm')
                        ->whereColumn('rm.persona_id', 'ep.id')
                        ->where('rm.evento_id', $this->eventoId)
                        ->where('rm.es_cabeza', 0);
                })
                ->orderBy('ep.nombre')
                ->get(['ep.cedula', 'ep.nombre', 'ep.telefono', 'ep.correo']);

            foreach ($ausentes as $a) {
                $rows[] = [
                    $a->cedula ?? '',
                    $a->nombre ?? '',
                    $a->telefono ?? '',
                    $a->correo ?? '',
                    '',
                    '',
                ];
            }

            $rows[] = [''];
            return $rows;
        }

        // ── MODO COEFICIENTE ─────────────────────────────────────────
        $rows[] = ['AUSENTES (NO REGISTRADOS)'];
        $rows[] = [''];
        $rows[] = ['Inmueble', 'Propietario', 'Coeficiente', 'Asistente', 'Celular', 'Correo'];

        $ausentes = DB::table('evento_padron as ep')
            ->where('ep.evento_id', $this->eventoId)
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('registros_checkin as rc')
                    ->whereColumn('rc.inmueble_base_id', 'ep.id')
                    ->where('rc.evento_id', $this->eventoId)
                    ->whereIn('rc.estado', ['CHECKED_IN', 'RETIRADO']);
            })
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('representacion_miembros as rm')
                    ->whereColumn('rm.padron_id', 'ep.id')
                    ->where('rm.evento_id', $this->eventoId)
                    ->where('rm.es_cabeza', 0);
            })
            ->orderBy('ep.inmueble')
            ->get([
                'ep.inmueble',
                'ep.propietario',
                'ep.coeficiente',
                'ep.asistente',
                'ep.celular_asistente',
                'ep.correo_asistente',
            ]);

        foreach ($ausentes as $a) {
            $rows[] = [
                $a->inmueble ?? '',
                $a->propietario ?? '',
                (float) ($a->coeficiente ?? 0),
                $a->asistente ?? '',
                $a->celular_asistente ?? '',
                $a->correo_asistente ?? '',
            ];
        }

        $rows[] = [''];
        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        $lastRow = $sheet->getHighestRow();

        // Título (A1:F1) — centrado
        $sheet->mergeCells('A1:F1');
        $sheet->getRowDimension(1)->setRowHeight(24);
        $sheet->getStyle('A1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FF1F3864');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FFFFFFFF');
        $sheet->getStyle('A1')->getAlignment()
            ->setHorizontal(Alignment::HORIZONTAL_CENTER)
            ->setVertical(Alignment::VERTICAL_CENTER);

        // Encabezado de tabla (fila 3)
        $sheet->getRowDimension(3)->setRowHeight(18);
        $sheet->getStyle('A3:F3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setARGB('FFD6E4F0');
        $sheet->getStyle('A3:F3')->getFont()->setBold(true);
        $sheet->getStyle('A3:F3')->getBorders()->getAllBorders()->setBorderStyle('thin');
        $sheet->getStyle('A3:F3')->getAlignment()->setWrapText(true);

        // Filas de datos (4+)
        if ($lastRow >= 4) {
            $sheet->getStyle("A4:F{$lastRow}")->getBorders()->getAllBorders()->setBorderStyle('thin');
            $sheet->getStyle("A4:F{$lastRow}")->getAlignment()->setWrapText(true);
        }

        // Inmovilizar desde fila 4
        $sheet->freezePane('A4');

        // Anchos de columna
        $sheet->getColumnDimension('A')->setWidth(18);
        $sheet->getColumnDimension('B')->setWidth(28);
        $sheet->getColumnDimension('C')->setWidth(12);
        $sheet->getColumnDimension('D')->setWidth(28);
        $sheet->getColumnDimension('E')->setWidth(16);
        $sheet->getColumnDimension('F')->setWidth(28);

        return [];
    }
}
