<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ControlsImport extends Command
{
    // ✅ Firma simple (sin --from por ahora)
    protected $signature = 'controls:import {evento_id} {--count=1000} {--wipe}';

    protected $description = 'Genera controles para un evento (1..N)';

    public function handle(): int
    {
        $eventoId = (int) $this->argument('evento_id');
        $count = (int) $this->option('count');
        $wipe = (bool) $this->option('wipe');

        // ✅ Validación básica
        if ($eventoId <= 0) {
            $this->error('evento_id inválido.');
            return self::FAILURE;
        }

        if ($count <= 0) {
            $this->error('--count debe ser mayor a 0.');
            return self::FAILURE;
        }

        // ✅ Validar que el evento exista
        $eventoExiste = DB::table('eventos')->where('id', $eventoId)->exists();
        if (!$eventoExiste) {
            $this->error("Evento {$eventoId} no existe en la tabla eventos.");
            return self::FAILURE;
        }

        DB::transaction(function () use ($eventoId, $count, $wipe) {
            if ($wipe) {
                DB::table('controles')->where('evento_id', $eventoId)->delete();
                $this->info("🧹 Controles del evento {$eventoId} eliminados.");
            }

            $now = now();
            $batch = [];

            for ($n = 1; $n <= $count; $n++) {
                $batch[] = [
                    'evento_id' => $eventoId,
                    'numero' => $n,
                    'serial' => str_pad((string) $n, 6, '0', STR_PAD_LEFT), // 000001
                    'estado' => 'LIBRE',
                    'asignado_a_registro_id' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                // Inserta en chunks de 1000
                if (count($batch) === 1000) {
                    DB::table('controles')->insert($batch);
                    $batch = [];
                }
            }

            if (!empty($batch)) {
                DB::table('controles')->insert($batch);
            }

            $this->info("✅ Controles generados: {$count} para evento {$eventoId}.");
        });

        return self::SUCCESS;
    }
}
