<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Domain\Event\Models\Evento;
use Illuminate\Support\Str;

class BackupEvento extends Command
{
    protected $signature = 'evento:backup {--note= : Nota opcional para el nombre del archivo}';
    protected $description = 'Genera un backup SQL de la base de datos actual en storage/app/backups';

    public function handle(): int
    {
        $db = config('database.connections.mysql.database');
        $user = config('database.connections.mysql.username');
        $pass = config('database.connections.mysql.password');
        $host = config('database.connections.mysql.host') ?? '127.0.0.1';

        if (!$db || !$user) {
            $this->error('No se pudo leer la configuración de la BD desde .env');
            return self::FAILURE;
        }

        $dir = storage_path('app/backups');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        /**
         * ✅ IMPORTANTE:
         * En Coefix el "evento activo" está por SESIÓN (web), pero Artisan corre en CLI (sin sesión).
         * Entonces:
         * 1) si existe is_active=true lo usamos (modo global)
         * 2) si solo hay 1 evento, usamos ese
         * 3) si hay varios, usamos el más reciente
         */
        $evento = Evento::where('is_active', true)->first();

        if (!$evento) {
            $count = Evento::count();

            if ($count === 1) {
                $evento = Evento::first();
            } elseif ($count > 1) {
                $evento = Evento::orderByDesc('id')->first();
            }
        }

        $eventoNombre = $evento
            ? Str::slug((string) $evento->titulo, '_')
            : 'sin_evento';

        // ✅ ultra-sanitizado (mata espacios raros, caracteres invisibles, etc.)
        $eventoNombre = strtolower($eventoNombre);
        $eventoNombre = preg_replace('/[^a-z0-9\-_]+/', '_', $eventoNombre);
        $eventoNombre = trim($eventoNombre, '_');

        $note = trim((string) $this->option('note'));
        $note = $note ? Str::slug($note, '_') : 'backup';

        $ts = now()->format('Ymd_His');

        // ✅ sanitizar DB name (evita espacios raros tipo "co efix")
        $dbSafe = preg_replace('/[^a-zA-Z0-9\-_]+/', '_', (string) $db);
        $dbSafe = strtolower(trim($dbSafe, '_'));

        $file = "{$dir}/{$ts}_{$eventoNombre}_{$dbSafe}_{$note}.sql";

        $cmd = sprintf(
            'mysqldump --host=%s --user=%s --password=%s --routines --triggers --single-transaction %s > %s',
            escapeshellarg($host),
            escapeshellarg($user),
            escapeshellarg($pass ?? ''),
            escapeshellarg($db),
            escapeshellarg($file)
        );

        $this->info("Ejecutando backup...");
        $exitCode = null;
        system($cmd, $exitCode);

        if ($exitCode !== 0 || !file_exists($file) || filesize($file) === 0) {
            $this->error("Falló el backup. Código: {$exitCode}");
            $this->line("Comando usado:\n{$cmd}");
            return self::FAILURE;
        }

        $this->info("✅ Backup creado: {$file}");
        return self::SUCCESS;
    }
}
