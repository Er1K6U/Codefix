<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportBaseEvento extends Command
{
    protected $signature = 'base:import {evento_id} {file} {--wipe : Borra la base anterior del evento antes de importar}';
    protected $description = 'Importa la Base del evento (Excel) a evento_padron y crea grupos base (cabeza sola)';

    public function handle(): int
    {
        $eventoId = (int) $this->argument('evento_id');
        $file = (string) $this->argument('file');
        $wipe = (bool) $this->option('wipe');

        if (!is_file($file)) {
            $this->error("No existe el archivo: {$file}");
            return self::FAILURE;
        }

        // Leer primera hoja como array (con encabezados)
        $sheets = app(\Maatwebsite\Excel\Excel::class)->toArray(null, $file);
        $rows = $sheets[0] ?? [];

        if (count($rows) < 2) {
            $this->error("El archivo no tiene filas suficientes (encabezado + datos).");
            return self::FAILURE;
        }

        $header = array_map(fn($h) => $this->norm((string) $h), $rows[0]);

        // Mapear columnas por nombre (robusto a tildes/espacios)
        $idx = $this->mapColumns($header);

        foreach (['inmueble', 'propietario', 'coeficiente'] as $req) {
            if ($idx[$req] === null) {
                $this->error("Falta columna obligatoria en Excel: {$req}");
                $this->line("Encabezados detectados: " . implode(', ', $header));
                return self::FAILURE;
            }
        }

        $data = [];
        $dupCheck = [];

        $sumCoef = 0.0;
        $count = 0;

        // Recorremos desde fila 2
        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];

            // Si la fila está vacía, saltar
            if (!is_array($r) || count(array_filter($r, fn($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }

            $inmueble = trim((string) ($r[$idx['inmueble']] ?? ''));
            $propietario = trim((string) ($r[$idx['propietario']] ?? ''));
            $coefRaw = $r[$idx['coeficiente']] ?? null;

            if ($inmueble === '' || $propietario === '' || $coefRaw === null || $coefRaw === '') {
                $this->warn("Fila " . ($i + 1) . " incompleta (inmueble/propietario/coeficiente). Se omite.");
                continue;
            }

            // Normalizar coeficiente
            $coef = $this->toDecimal($coefRaw);

            // Duplicados por inmueble en el mismo archivo
            $key = mb_strtoupper($inmueble);
            if (isset($dupCheck[$key])) {
                $this->error("Duplicado de inmueble en Excel: {$inmueble} (fila " . ($i + 1) . ")");
                return self::FAILURE;
            }
            $dupCheck[$key] = true;

            $asistente = $idx['asistente'] !== null ? trim((string) ($r[$idx['asistente']] ?? '')) : null;
            $celular = $idx['celular'] !== null ? trim((string) ($r[$idx['celular']] ?? '')) : null;
            $correo = $idx['correo'] !== null ? trim((string) ($r[$idx['correo']] ?? '')) : null;

            $data[] = [
                'evento_id' => $eventoId,
                'inmueble' => $inmueble,
                'propietario' => $propietario,
                'coeficiente' => $coef,
                'asistente' => $asistente !== '' ? $asistente : null,
                'celular_asistente' => $celular !== '' ? $celular : null,
                'correo_asistente' => $correo !== '' ? $correo : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $sumCoef += (float) $coef;
            $count++;
        }

        if ($count === 0) {
            $this->error("No se encontraron filas válidas para importar.");
            return self::FAILURE;
        }

        // Validación fuerte: suma coeficiente ~ 100
        // Permitimos pequeñas variaciones por decimales (ej 99.9999 / 100.0001)
        if (abs($sumCoef - 100.0) > 0.01) {
            $this->error("La suma de coeficientes NO da 100. Da: " . number_format($sumCoef, 4, '.', ''));
            return self::FAILURE;
        }

        DB::transaction(function () use ($eventoId, $wipe, $data, $count) {
            if ($wipe) {
                // ✅ Ahora que representacion_miembros tiene evento_id, borramos directo por evento
                DB::table('representacion_miembros')
                    ->where('evento_id', $eventoId)
                    ->delete();

                DB::table('representacion_grupos')->where('evento_id', $eventoId)->delete();

                DB::table('evento_padron')->where('evento_id', $eventoId)->delete();
            }

            // Insert por chunks (rendimiento)
            foreach (array_chunk($data, 500) as $chunk) {
                DB::table('evento_padron')->insert($chunk);
            }

            // Crear grupos base: cada inmueble arranca como cabeza de su propio grupo
            $padron = DB::table('evento_padron')
                ->select('id')
                ->where('evento_id', $eventoId)
                ->get();

            foreach ($padron as $p) {
                $grupoId = DB::table('representacion_grupos')->insertGetId([
                    'evento_id' => $eventoId,
                    'cabeza_padron_id' => $p->id,
                    'control_numero' => null,
                    'control_serial' => null,
                    'checkin_at' => null,
                    'checkin_by' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('representacion_miembros')->insert([
                    'grupo_id' => $grupoId,
                    'evento_id' => $eventoId,
                    'padron_id' => $p->id,
                    'es_cabeza' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        $this->info("✅ Importación OK. Filas: {$count} | Suma coef: " . number_format($sumCoef, 4, '.', ''));
        $this->info("✅ Grupos base creados (1 por inmueble).");

        return self::SUCCESS;
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'u'],
            $s
        );
        $s = preg_replace('/[^a-z0-9]+/u', '_', $s);
        return trim($s, '_');
    }

    private function mapColumns(array $header): array
    {
        // Diccionario de sinónimos: ajustable si cambias nombres
        $syn = [
            'inmueble' => ['inmueble', 'referencia', 'unidad', 'apto', 'apartamento', 'inmueble_no'],
            'propietario' => ['propietario', 'dueno', 'dueño', 'nombre_propietario'],
            'coeficiente' => ['coeficiente', 'coef', 'coefic', 'coeficiente_de_copropiedad'],

            'asistente' => ['asistente', 'representante', 'nombre_asistente'],
            'celular' => ['celular_asistente', 'celular', 'telefono', 'telefono_asistente', 'movil'],
            'correo' => ['correo_asistente', 'correo', 'email', 'email_asistente', 'mail'],
        ];

        $idx = [
            'inmueble' => null,
            'propietario' => null,
            'coeficiente' => null,
            'asistente' => null,
            'celular' => null,
            'correo' => null,
        ];

        foreach ($header as $i => $h) {
            foreach ($syn as $key => $names) {
                if (in_array($h, array_map([$this, 'norm'], $names), true)) {
                    $idx[$key] = $i;
                }
            }
        }

        return $idx;
    }

    private function toDecimal($value): float
    {
        // Acepta 0.43, 0,43, "0.4300", etc.
        $v = is_string($value) ? trim($value) : $value;
        if (is_string($v)) {
            $v = str_replace([' ', "\u{00A0}"], '', $v);
            $v = str_replace(',', '.', $v);
        }
        return (float) $v;
    }
}
