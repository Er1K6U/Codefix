<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ImportBaseNominal extends Command
{
    protected $signature = 'base:import-nominal
                            {--wipe : Borra las personas anteriores del evento antes de importar}
                            {--file= : Ruta del archivo Excel (sobreescribe personas_excel_path del evento)}';

    protected $description = 'Importa la base nominal (cédulas/personas) a evento_personas';

    public function handle(): int
    {
        // Selección de evento: is_active primero, luego el último
        $evento = DB::table('eventos')->where('is_active', 1)->orderByDesc('id')->first();
        if (!$evento) {
            $evento = DB::table('eventos')->orderByDesc('id')->first();
        }

        if (!$evento) {
            $this->error('No existe ningún evento en la base de datos.');
            return self::FAILURE;
        }

        if (($evento->tipo_quorum ?? 'coeficiente') !== 'nominal') {
            $this->error("El evento #{$evento->id} ({$evento->titulo}) tiene tipo_quorum = '{$evento->tipo_quorum}', no 'nominal'.");
            $this->line('Cambia el tipo de quórum del evento a nominal antes de importar.');
            return self::FAILURE;
        }

        $eventoId = (int) $evento->id;

        // Resolver ruta del archivo
        $relativePath = $this->option('file') ?? ($evento->personas_excel_path ?? null);

        if (!$relativePath) {
            $this->error("El evento #{$eventoId} no tiene personas_excel_path configurado.");
            $this->line('Usa --file=/ruta/al/archivo.xlsx para especificar el archivo.');
            return self::FAILURE;
        }

        // Si viene de --file puede ser ruta absoluta o relativa al storage
        $file = file_exists($relativePath) ? $relativePath : Storage::path($relativePath);

        if (!is_file($file)) {
            $this->error("No existe el archivo en disco: {$file}");
            return self::FAILURE;
        }

        $this->info("Evento seleccionado: #{$eventoId} - {$evento->titulo}");
        $this->info("Archivo nominal: {$relativePath}");

        // Leer primera hoja
        $sheets = app(\Maatwebsite\Excel\Excel::class)->toArray(null, $file);
        $rows = $sheets[0] ?? [];

        if (count($rows) < 2) {
            $this->error('El archivo no tiene filas suficientes (encabezado + datos).');
            return self::FAILURE;
        }

        $header = array_map(fn($h) => $this->norm((string) $h), $rows[0]);
        $idx = $this->mapColumns($header);

        foreach (['cedula', 'nombre'] as $req) {
            if ($idx[$req] === null) {
                $this->error("Falta columna obligatoria en Excel: {$req}");
                $this->line('Encabezados detectados: ' . implode(', ', $header));
                return self::FAILURE;
            }
        }

        $data = [];
        $dupCheck = [];
        $count = 0;

        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];

            if (!is_array($r) || count(array_filter($r, fn($v) => $v !== null && $v !== '')) === 0) {
                continue;
            }

            $cedula = trim((string) ($r[$idx['cedula']] ?? ''));
            $nombre = trim((string) ($r[$idx['nombre']] ?? ''));

            if ($cedula === '' || $nombre === '') {
                $this->warn("Fila " . ($i + 1) . " incompleta (cédula/nombre). Se omite.");
                continue;
            }

            // Normalizar cédula: solo dígitos y letras, sin puntos ni comas
            $cedula = preg_replace('/[\s.,]/', '', $cedula);

            $key = mb_strtoupper($cedula);
            if (isset($dupCheck[$key])) {
                $this->error("Cédula duplicada en Excel: {$cedula} (fila " . ($i + 1) . ")");
                return self::FAILURE;
            }
            $dupCheck[$key] = true;

            $telefono = $idx['telefono'] !== null ? trim((string) ($r[$idx['telefono']] ?? '')) : null;
            $correo   = $idx['correo']   !== null ? trim((string) ($r[$idx['correo']]   ?? '')) : null;

            $data[] = [
                'evento_id' => $eventoId,
                'cedula'    => $cedula,
                'nombre'    => $nombre,
                'telefono'  => ($telefono !== '' && $telefono !== null) ? $telefono : null,
                'correo'    => ($correo   !== '' && $correo   !== null) ? $correo   : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $count++;
        }

        if ($count === 0) {
            $this->error('No se encontraron filas válidas para importar.');
            return self::FAILURE;
        }

        $gruposCreados = 0;

        DB::transaction(function () use ($eventoId, $data, &$gruposCreados) {
            if ($this->option('wipe')) {
                // CASCADE borra grupos_nominal y miembros_nominal automáticamente
                DB::table('evento_personas')->where('evento_id', $eventoId)->delete();
            }

            foreach (array_chunk($data, 500) as $chunk) {
                DB::table('evento_personas')->insert($chunk);
            }

            // Personas que aún no tienen grupo base (idempotente sin --wipe)
            $sinGrupo = DB::table('evento_personas as ep')
                ->leftJoin('representacion_grupos_nominal as rgn', 'rgn.cabeza_persona_id', '=', 'ep.id')
                ->where('ep.evento_id', $eventoId)
                ->whereNull('rgn.id')
                ->pluck('ep.id');

            if ($sinGrupo->isNotEmpty()) {
                $now = now();

                $gruposData = $sinGrupo->map(fn($id) => [
                    'evento_id'         => $eventoId,
                    'cabeza_persona_id' => $id,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ])->all();

                foreach (array_chunk($gruposData, 500) as $chunk) {
                    DB::table('representacion_grupos_nominal')->insert($chunk);
                }

                $grupos = DB::table('representacion_grupos_nominal')
                    ->where('evento_id', $eventoId)
                    ->whereIn('cabeza_persona_id', $sinGrupo->all())
                    ->pluck('id', 'cabeza_persona_id');

                $miembrosData = $sinGrupo->map(fn($id) => [
                    'grupo_id'   => $grupos[$id],
                    'evento_id'  => $eventoId,
                    'persona_id' => $id,
                    'es_cabeza'  => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();

                foreach (array_chunk($miembrosData, 500) as $chunk) {
                    DB::table('representacion_miembros_nominal')->insert($chunk);
                }

                $gruposCreados = $sinGrupo->count();
            }
        });

        $this->info("✅ Importación nominal OK. Personas: {$count} | Grupos base: {$gruposCreados}");

        return self::SUCCESS;
    }

    private function norm(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ñ', 'ü'],
            ['a', 'e', 'i', 'o', 'u', 'n', 'u']
        , $s);
        $s = preg_replace('/[^a-z0-9]+/u', '_', $s);
        return trim($s, '_');
    }

    private function mapColumns(array $header): array
    {
        $syn = [
            'cedula'   => ['cedula', 'documento', 'cc', 'num_documento', 'numero_documento', 'identificacion', 'nit'],
            'nombre'   => ['nombre', 'nombre_completo', 'nombres', 'name', 'nombre_persona'],
            'telefono' => ['telefono', 'celular', 'movil', 'phone', 'celular_asistente', 'telefono_asistente'],
            'correo'   => ['correo', 'email', 'mail', 'correo_asistente', 'email_asistente'],
        ];

        $idx = ['cedula' => null, 'nombre' => null, 'telefono' => null, 'correo' => null];

        foreach ($header as $i => $h) {
            foreach ($syn as $key => $names) {
                if ($idx[$key] === null && in_array($h, array_map([$this, 'norm'], $names), true)) {
                    $idx[$key] = $i;
                }
            }
        }

        return $idx;
    }
}
