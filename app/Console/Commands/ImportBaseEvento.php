<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportBaseEvento extends Command
{
    protected $signature = 'base:import
                            {evento_id? : Evento destino (obligatorio si hay más de un evento)}
                            {--wipe : Borra la base anterior del evento antes de importar}';
    protected $description = 'Importa la Base del evento (Excel) a evento_padron y crea grupos base (cabeza sola)';

    public function handle(): int
    {
        $wipe = (bool) $this->option('wipe');

        $evento = $this->eventoDestino();

        if (!$evento) {
            return self::FAILURE;
        }

        $eventoId = (int) $evento->id;

        // ✅ El archivo se toma desde la BD (lo cargaste al crear/editar evento)
        $relativePath = $evento->base_excel_path ?? null;

        if (!$relativePath) {
            $this->error("El evento #{$eventoId} NO tiene base_excel_path en BD. Sube el Excel Base en el formulario del evento.");
            return self::FAILURE;
        }

        // En tu proyecto el disco local apunta a storage/app/private (por eso lo ves así en VSCode)
        // Storage::path() devuelve la ruta real en el disco.
        $file = Storage::path($relativePath);

        if (!is_file($file)) {
            $this->error("No existe el archivo en disco: {$file}");
            $this->line("Ruta guardada en BD: {$relativePath}");
            return self::FAILURE;
        }

        $this->info("Evento seleccionado: #{$eventoId} - {$evento->titulo}");
        $this->info("Archivo base: {$relativePath}");

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
        // ✅ Validación flexible: suma coeficiente ~ 100 con tolerancia (Excel-friendly)
        $sumCoef = round($sumCoef, 2);   // redondeo consistente a 2 decimales
        $epsilon = 0.10;                 // tolerancia permitida (±0.10)

        if (abs($sumCoef - 100.00) > $epsilon) {
            $this->error(
                "La suma de coeficientes debe ser 100% (±" . number_format($epsilon, 2) . "). " .
                "Da: " . number_format($sumCoef, 2, '.', '')
            );
            return self::FAILURE;
        }

        DB::transaction(function () use ($eventoId, $wipe, $data) {
            if ($wipe) {
                DB::table('representacion_miembros')->where('evento_id', $eventoId)->delete();
                DB::table('representacion_grupos')->where('evento_id', $eventoId)->delete();
                DB::table('evento_padron')->where('evento_id', $eventoId)->delete();
            }

            foreach (array_chunk($data, 500) as $chunk) {
                DB::table('evento_padron')->insert($chunk);
            }

            // Crear grupos base: cada inmueble arranca como cabeza de su propio grupo
            $padron = DB::table('evento_padron')
                ->select('id')
                ->where('evento_id', $eventoId)
                ->get();

            // Por lotes: primero los grupos y luego sus cabezas. El id de cada grupo se busca por su
            // cabeza (única por evento), sin suponer ids consecutivos.
            $now = now();

            $gruposData = $padron->map(fn($p) => [
                'evento_id' => $eventoId,
                'cabeza_padron_id' => $p->id,
                'control_numero' => null,
                'control_serial' => null,
                'checkin_at' => null,
                'checkin_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($gruposData, 500) as $chunk) {
                DB::table('representacion_grupos')->insert($chunk);
            }

            $grupos = DB::table('representacion_grupos')
                ->where('evento_id', $eventoId)
                ->pluck('id', 'cabeza_padron_id');

            $miembrosData = $padron->map(fn($p) => [
                'grupo_id' => $grupos[$p->id],
                'evento_id' => $eventoId,
                'padron_id' => $p->id,
                'es_cabeza' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            foreach (array_chunk($miembrosData, 500) as $chunk) {
                DB::table('representacion_miembros')->insert($chunk);
            }
        });

        $this->info("✅ Importación OK. Filas: {$count} | Suma coef: " . number_format($sumCoef, 4, '.', ''));
        $this->info("✅ Grupos base creados (1 por inmueble).");

        return self::SUCCESS;
    }

    /**
     * Evento al que se importa: el indicado, o el único evento de la instalación.
     * Nunca se elige por is_active ni por ser el más reciente, para no borrar otra asamblea.
     */
    private function eventoDestino(): ?object
    {
        $id = $this->argument('evento_id');

        if ($id !== null) {
            $evento = ctype_digit((string) $id) ? DB::table('eventos')->where('id', (int) $id)->first() : null;

            if (!$evento) {
                $this->error("No existe el evento #{$id}.");
            }

            return $evento;
        }

        $eventos = DB::table('eventos')->limit(2)->get();

        if ($eventos->count() === 1) {
            return $eventos->first();
        }

        $this->error($eventos->isEmpty()
            ? "No existe ningún evento en la base de datos."
            : "Hay varios eventos. Indica el evento destino: php artisan base:import <evento_id>");

        return null;
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
        $v = is_string($value) ? trim($value) : $value;
        if (is_string($v)) {
            $v = str_replace([' ', "\u{00A0}"], '', $v);
            $v = str_replace(',', '.', $v);
        }
        return (float) $v;
    }
}
