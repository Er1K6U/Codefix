<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportControlsFromExcel extends Command
{
    protected $signature = 'controls:import-excel {evento_id} {--wipe}';
    protected $description = 'Importa controles (numero + serial) desde el Excel cargado en el evento.';

    public function handle(): int
    {
        $eventoId = (int) $this->argument('evento_id');
        $wipe = (bool) $this->option('wipe');

        $evento = DB::table('eventos')->where('id', $eventoId)->first();
        if (!$evento) {
            $this->error("No existe el evento #{$eventoId}.");
            return self::FAILURE;
        }

        if (empty($evento->controles_excel_path)) {
            $this->error("El evento #{$eventoId} no tiene controles_excel_path en BD.");
            return self::FAILURE;
        }

        // En tu proyecto guardas rutas tipo: "eventos/12/controles.xlsx"
        // y físicamente están en: storage/app/private/eventos/12/controles.xlsx
        $relative = (string) $evento->controles_excel_path;
        $diskPath = Storage::disk('private')->path($relative);

        if (!file_exists($diskPath)) {
            $this->error("No se encontró el archivo en disco: {$diskPath}");
            return self::FAILURE;
        }

        $this->info("Evento seleccionado: #{$eventoId} - {$evento->titulo}");
        $this->info("Archivo controles: {$relative}");

        // Leer Excel
        $spreadsheet = IOFactory::load($diskPath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if (count($rows) < 2) {
            $this->error("El Excel no tiene filas suficientes (cabecera + datos).");
            return self::FAILURE;
        }

        // Normalizar headers (fila 1)
        $headerRow = array_shift($rows);
        $headers = [];
        foreach ($headerRow as $col => $name) {
            $key = mb_strtolower(trim((string) $name));
            $key = str_replace([' ', '-', '.'], '_', $key);
            $headers[$col] = $key;
        }

        // Buscamos columnas esperadas (permitimos varias variantes)
        $colNumero = array_search('numero_control', $headers, true);
        if ($colNumero === false)
            $colNumero = array_search('numero', $headers, true);

        $colCodigo = array_search('codigo_control', $headers, true);
        if ($colCodigo === false)
            $colCodigo = array_search('codigo', $headers, true);
        if ($colCodigo === false)
            $colCodigo = array_search('serial', $headers, true);

        if ($colNumero === false || $colCodigo === false) {
            $this->error("No se encontraron columnas requeridas. Se esperaba 'Numero_control' y 'Codigo_control' (o variantes).");
            $this->line("Headers detectados: " . json_encode(array_values($headers), JSON_UNESCAPED_UNICODE));
            return self::FAILURE;
        }

        // Parsear filas
        $items = [];
        $seenNumero = [];
        $seenSerial = [];

        foreach ($rows as $i => $r) {
            $rawNumero = trim((string) ($r[$colNumero] ?? ''));
            $rawSerial = trim((string) ($r[$colCodigo] ?? ''));

            if ($rawNumero === '' && $rawSerial === '') {
                continue; // fila vacía
            }

            if ($rawNumero === '' || !ctype_digit($rawNumero)) {
                $this->error("Fila " . ($i + 2) . ": Numero_control inválido: '{$rawNumero}'");
                return self::FAILURE;
            }

            $numero = (int) $rawNumero;

            if ($rawSerial === '') {
                $this->error("Fila " . ($i + 2) . ": Codigo_control/serial vacío para el control #{$numero}");
                return self::FAILURE;
            }

            if (isset($seenNumero[$numero])) {
                $this->error("Duplicado en Excel: Numero_control {$numero}");
                return self::FAILURE;
            }
            $seenNumero[$numero] = true;

            $serialKey = mb_strtolower($rawSerial);
            if (isset($seenSerial[$serialKey])) {
                $this->error("Duplicado en Excel: Codigo_control/serial '{$rawSerial}'");
                return self::FAILURE;
            }
            $seenSerial[$serialKey] = true;

            $items[] = [
                'evento_id' => $eventoId,
                'numero' => $numero,
                'serial' => $rawSerial,
                'estado' => 'LIBRE',
                'asignado_a_registro_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if (empty($items)) {
            $this->error("No se encontraron filas válidas para importar.");
            return self::FAILURE;
        }

        DB::transaction(function () use ($eventoId, $wipe, $items) {
            if ($wipe) {
                DB::table('controles')->where('evento_id', $eventoId)->delete();
            }

            DB::table('controles')->insert($items);
        });

        $this->info("✅ Controles importados: " . count($items) . " para evento {$eventoId}" . ($wipe ? " (wipe)" : "") . ".");
        return self::SUCCESS;
    }
}
