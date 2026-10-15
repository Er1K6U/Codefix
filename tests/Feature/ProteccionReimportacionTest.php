<?php

namespace Tests\Feature;

use App\Livewire\Quorum\Show as Quorum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Protección contra reimportaciones destructivas (COEFIX 2.0, Etapa 1H-R).
 *
 * Con --wipe, los cuatro comandos de importación se niegan si la asamblea ya tiene actividad:
 * asistencia en cualquier estado, poderes, grupos con check-in o controles entregados. La
 * comprobación ocurre dentro de la transacción y antes del primer borrado.
 */
class ProteccionReimportacionTest extends TestCase
{
    use RefreshDatabase;

    private string $disco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $this->disco = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coefix_proteccion_' . uniqid();
        File::ensureDirectoryExists($this->disco);
        config([
            'filesystems.disks.local.root' => $this->disco,
            'filesystems.disks.private.root' => $this->disco,
            'excel.temporary_files.local_path' => $this->disco,
        ]);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->disco);
        parent::tearDown();
    }

    private const BASE = [['Inmueble', 'Propietario', 'Coeficiente'], ['101', 'Ana', 40], ['102', 'Bruno', 25], ['103', 'Carla', 20], ['104', 'Diego', 15]];
    private const PERSONAS = [['Cedula', 'Nombre'], ['111', 'Ana'], ['222', 'Bruno'], ['333', 'Carla']];
    private const CONTROLES = [['Numero_control', 'Codigo_control'], [1, 'C-1'], [2, 'C-2'], [3, 'C-3']];

    // ── W1–W6 ───────────────────────────────────────────────────────

    public function test_w1_base_con_actividad_se_bloquea_sin_borrar_ni_duplicar_quorum(): void
    {
        $e = $this->asambleaCoefEnCurso();
        [$foto, $quorum] = [$this->foto(), $this->quorum($e)];

        $r = $this->comando('base:import', ['evento_id' => $e, '--wipe' => true]);

        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('No se reemplazó la base de inmuebles', $r['salida']);
        $this->assertStringContainsString('asistencias: 2, poderes: 1, grupos con check-in: 2, controles entregados: 1', $r['salida']);
        $this->assertSame($foto, $this->foto(), 'no se borró ni cambió nada');
        $this->assertSame($quorum, $this->quorum($e), 'el quórum sigue igual (sin huérfanos que dupliquen)');
        $this->assertSame(65.0, (float) $this->quorum($e)['quorumActual']);
    }

    public function test_w2_nominal_con_actividad_se_bloquea_y_el_quorum_no_cae(): void
    {
        $e = $this->asambleaNominalEnCurso();
        [$foto, $quorum] = [$this->foto(), $this->quorum($e)];

        $r = $this->comando('base:import-nominal', ['evento_id' => $e, '--wipe' => true]);

        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('No se reemplazó la base de personas', $r['salida']);
        $this->assertSame($foto, $this->foto());
        $this->assertSame($quorum, $this->quorum($e));
        $this->assertSame(2, $this->quorum($e)['personasCheckin'], 'la asistencia nominal sigue contando');
    }

    public function test_w3_controles_excel_con_control_entregado_se_bloquea(): void
    {
        $e = $this->asambleaCoefEnCurso();
        $foto = $this->foto();

        $r = $this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true]);

        $this->assertSame(1, $r['exit']);
        $this->assertStringContainsString('No se reemplazó la lista de controles', $r['salida']);
        $this->assertSame($foto, $this->foto());
        $this->assertSame('ASIGNADO', DB::table('controles')->where('evento_id', $e)->where('numero', 1)->value('estado'), 'el control entregado no vuelve a LIBRE');
    }

    public function test_w4_generador_de_controles_con_actividad_se_bloquea(): void
    {
        $e = $this->asambleaCoefEnCurso();
        $foto = $this->foto();

        $r = $this->comando('controls:import', ['evento_id' => $e, '--count' => 3, '--wipe' => true]);

        $this->assertSame(1, $r['exit']);
        $this->assertSame($foto, $this->foto());
        $this->assertSame('ASIGNADO', DB::table('controles')->where('evento_id', $e)->where('numero', 1)->value('estado'));
    }

    public function test_w5_reimportar_sin_actividad_se_permite_y_da_lo_mismo(): void
    {
        $e = $this->eventoCoef();
        $this->assertSame(0, $this->comando('base:import', ['evento_id' => $e, '--wipe' => true])['exit'], 'importación inicial');
        $this->assertSame(0, $this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true])['exit']);
        $inicial = $this->foto(true);

        $this->assertSame(0, $this->comando('base:import', ['evento_id' => $e, '--wipe' => true])['exit']);
        $this->assertSame(0, $this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true])['exit']);
        $this->assertSame(0, $this->comando('controls:import', ['evento_id' => $e, '--count' => 3, '--wipe' => true])['exit']);
        $this->assertSame(0, $this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true])['exit']);

        $this->assertSame($inicial, $this->foto(true), 'misma base, grupos y controles que la importación inicial');
        $this->assertSame(0, DB::table('audit_logs')->where('accion', 'reimportacion_bloqueada')->count());
    }

    public function test_w5_nominal_reimportar_sin_actividad_se_permite(): void
    {
        $e = $this->eventoNominal();
        $this->assertSame(0, $this->comando('base:import-nominal', ['evento_id' => $e, '--wipe' => true])['exit']);
        $inicial = $this->foto(true);
        $this->assertSame(0, $this->comando('base:import-nominal', ['evento_id' => $e, '--wipe' => true])['exit']);
        $this->assertSame($inicial, $this->foto(true));
    }

    public function test_w6_fallo_despues_del_borrado_revierte_todo(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('SQLite no limita el largo de inmueble; el fallo a mitad se provoca solo en MySQL estricto.');
        }
        $e = $this->eventoCoef();
        $this->comando('base:import', ['evento_id' => $e, '--wipe' => true]);
        $antes = $this->foto();
        // Sin actividad el --wipe está permitido; el INSERT falla después de los DELETE (varchar(20)).
        DB::table('eventos')->where('id', $e)->update(['base_excel_path' => $this->xlsx('a/largo.xlsx', [['Inmueble', 'Propietario', 'Coeficiente'], ['101', 'Ana', 50], [str_repeat('X', 25), 'Largo', 50]])]);

        $r = $this->comando('base:import', ['evento_id' => $e, '--wipe' => true]);

        $this->assertSame('QueryException', $r['excepcion'] ?? null);
        $this->assertSame($antes, $this->foto(), 'la transacción revierte el borrado');
        $this->assertSame(0, DB::table('audit_logs')->where('accion', 'reimportacion_bloqueada')->count(), 'un fallo no se audita como bloqueo');
    }

    // ── Cada señal de actividad por separado, en los cuatro comandos ──

    public static function senales(): array
    {
        $senales = ['asistencia_en_proceso', 'asistencia_registrada', 'retiro', 'reingreso', 'poder_coeficiente', 'poder_nominal',
            'grupo_con_checkin', 'grupo_con_control', 'grupo_nominal_con_checkin', 'control_asignado', 'control_con_registro'];
        $comandos = ['base:import', 'base:import-nominal', 'controls:import-excel', 'controls:import'];
        $casos = [];
        foreach ($senales as $s) {
            foreach ($comandos as $c) {
                $casos["{$s} / {$c}"] = [$s, $c];
            }
        }

        return $casos;
    }

    #[DataProvider('senales')]
    public function test_cada_senal_de_actividad_bloquea_cada_comando(string $senal, string $comando): void
    {
        // Evento con base de inmuebles, personas y controles a la vez: cualquier señal puede sembrarse
        // y cualquiera de los cuatro comandos puede ejecutarse sobre él.
        $e = $this->eventoNominal([
            'base_excel_path' => $this->xlsx('m/base.xlsx', self::BASE),
            'controles_excel_path' => $this->xlsx('m/controles.xlsx', self::CONTROLES),
        ]);
        foreach (['base:import', 'base:import-nominal', 'controls:import-excel'] as $inicial) {
            $this->assertSame(0, $this->comando($inicial, ['evento_id' => $e, '--wipe' => true])['exit'], "importación inicial {$inicial}");
        }
        $this->sembrarSenal($e, $senal, str_contains($senal, 'nominal'));
        $foto = $this->foto();

        $args = ['evento_id' => $e, '--wipe' => true] + ($comando === 'controls:import' ? ['--count' => 3] : []);
        $r = $this->comando($comando, $args);

        $this->assertSame(1, $r['exit'] ?? null, "{$senal} debe bloquear {$comando}");
        $this->assertStringContainsString('ya tiene actividad', $r['salida']);
        $this->assertSame($foto, $this->foto(), 'nada cambió');
        $audit = DB::table('audit_logs')->where('accion', 'reimportacion_bloqueada')->first();
        $this->assertNotNull($audit, 'el bloqueo queda en la auditoría');
        $this->assertSame($comando, json_decode($audit->meta, true)['comando']);
        $this->assertSame($e, (int) $audit->subject_id);
    }

    // ── Otras garantías ─────────────────────────────────────────────

    public function test_no_se_modifica_otro_evento(): void
    {
        $enCurso = $this->asambleaCoefEnCurso();
        $nueva = $this->eventoCoef();
        $fotoEnCurso = $this->foto(false, $enCurso);

        $this->assertSame(0, $this->comando('base:import', ['evento_id' => $nueva, '--wipe' => true])['exit'], 'la otra asamblea con actividad no bloquea a esta');
        $this->assertSame(0, $this->comando('controls:import-excel', ['evento_id' => $nueva, '--wipe' => true])['exit']);
        $this->assertSame($fotoEnCurso, $this->foto(false, $enCurso));

        $this->assertSame(1, $this->comando('base:import', ['evento_id' => $enCurso, '--wipe' => true])['exit']);
        $this->assertSame(4, DB::table('evento_padron')->where('evento_id', $nueva)->count(), 'el bloqueo de una no toca la otra');
    }

    public function test_sin_wipe_no_cambia_el_comportamiento(): void
    {
        // Sin --wipe no hay reemplazo: nominal agrega personas aunque haya asistencia; los controles nuevos se agregan.
        // (Sin poderes: con poderes, la nominal sin --wipe ya fallaba antes; defecto registrado aparte.)
        $e = $this->eventoNominal(['controles_excel_path' => $this->xlsx('n/controles.xlsx', [['Numero_control', 'Codigo_control'], [7, 'C-7']])]);
        $this->comando('base:import-nominal', ['evento_id' => $e, '--wipe' => true]);
        $this->registroNominal($e, '111', 1);
        DB::table('eventos')->where('id', $e)->update(['personas_excel_path' => $this->xlsx('n/mas.xlsx', [['Cedula', 'Nombre'], ['444', 'Diana']])]);

        $this->assertSame(0, $this->comando('base:import-nominal', ['evento_id' => $e])['exit']);
        $this->assertSame(0, $this->comando('controls:import-excel', ['evento_id' => $e])['exit']);
        $this->assertSame(4, DB::table('evento_personas')->where('evento_id', $e)->count());
        $this->assertSame(0, DB::table('audit_logs')->where('accion', 'reimportacion_bloqueada')->count());
    }

    public function test_errores_de_archivo_y_destino_no_borran_nada(): void
    {
        $e = $this->asambleaCoefEnCurso();
        $foto = $this->foto();

        DB::table('eventos')->where('id', $e)->update(['base_excel_path' => $this->xlsx('a/dup.xlsx', [['Inmueble', 'Propietario', 'Coeficiente'], ['101', 'A', 50], ['101', 'B', 50]])]);
        $this->assertSame(1, $this->comando('base:import', ['evento_id' => $e, '--wipe' => true])['exit'], 'duplicado en Excel');
        DB::table('eventos')->where('id', $e)->update(['base_excel_path' => 'a/no-existe.xlsx']);
        $this->assertSame(1, $this->comando('base:import', ['evento_id' => $e, '--wipe' => true])['exit'], 'archivo inexistente');
        $this->assertSame(1, $this->comando('base:import', ['evento_id' => 999999, '--wipe' => true])['exit'], 'destino inexistente');
        $this->eventoCoef();
        $this->assertSame(1, $this->comando('base:import', ['--wipe' => true])['exit'], 'varios eventos sin destino');

        $this->assertSame($foto, $this->foto(false, $e));
        $this->assertSame(0, DB::table('audit_logs')->where('accion', 'reimportacion_bloqueada')->count(), 'los errores de archivo o destino no se auditan como bloqueo');
    }

    public function test_el_bloqueo_se_audita_fuera_de_la_transaccion_revertida(): void
    {
        $e = $this->asambleaCoefEnCurso();
        $nivel = DB::transactionLevel();
        $nivelAlAuditar = null;
        \App\Models\AuditLog::creating(function () use (&$nivelAlAuditar) {
            $nivelAlAuditar = DB::transactionLevel();
        });

        $this->assertSame(1, $this->comando('base:import', ['evento_id' => $e, '--wipe' => true])['exit']);

        $this->assertSame($nivel, $nivelAlAuditar, 'la auditoría se escribe después de revertir la transacción de la importación');
        $this->assertSame($nivel, DB::transactionLevel());
        $this->assertSame(1, DB::table('audit_logs')->where('accion', 'reimportacion_bloqueada')->count());
    }

    // ── Apoyo ───────────────────────────────────────────────────────

    private function comando(string $nombre, array $args): array
    {
        try {
            $exit = Artisan::call($nombre, $args);

            return ['exit' => $exit, 'salida' => trim(Artisan::output())];
        } catch (\Throwable $e) {
            return ['excepcion' => class_basename($e)];
        }
    }

    private function xlsx(string $relativa, array $filas): string
    {
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray($filas, null, 'A1', true);
        File::ensureDirectoryExists(dirname("{$this->disco}/{$relativa}"));
        (new Xlsx($libro))->save("{$this->disco}/{$relativa}");

        return $relativa;
    }

    private function eventoCoef(array $extra = []): int
    {
        return DB::table('eventos')->insertGetId($extra + [
            'titulo' => 'Asamblea coeficiente', 'slug' => 'c-' . uniqid(), 'fecha_inicio' => '2026-10-15', 'activo' => 1, 'tipo_quorum' => 'coeficiente',
            'base_excel_path' => $this->xlsx('a/base.xlsx', self::BASE), 'controles_excel_path' => $this->xlsx('a/controles.xlsx', self::CONTROLES),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function eventoNominal(array $extra = []): int
    {
        return DB::table('eventos')->insertGetId($extra + [
            'titulo' => 'Asamblea nominal', 'slug' => 'n-' . uniqid(), 'fecha_inicio' => '2026-10-15', 'activo' => 1, 'tipo_quorum' => 'nominal',
            'personas_excel_path' => $this->xlsx('n/personas.xlsx', self::PERSONAS), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** W1/W3/W4: poder (101 representa a 102), 101 registrado con el control 1, 103 retirado. */
    private function asambleaCoefEnCurso(): int
    {
        $e = $this->eventoCoef();
        $this->comando('base:import', ['evento_id' => $e, '--wipe' => true]);
        $this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true]);
        $this->poderCoef($e, '101', '102');
        $this->registro($e, '101', 1, 65, 'CHECKED_IN');
        $this->registro($e, '103', 2, 20, 'RETIRADO');

        return $e;
    }

    /** W2: poder (111 representa a 222) y 111 registrado. */
    private function asambleaNominalEnCurso(array $extra = []): int
    {
        $e = $this->eventoNominal($extra);
        $this->comando('base:import-nominal', ['evento_id' => $e, '--wipe' => true]);
        $this->poderNominal($e, '111', '222');
        $this->registroNominal($e, '111', 2);

        return $e;
    }

    private function poderCoef(int $e, string $cabeza, string $representado): void
    {
        $p = DB::table('evento_padron')->where('evento_id', $e)->pluck('id', 'inmueble');
        $g = DB::table('representacion_grupos')->where('evento_id', $e)->pluck('id', 'cabeza_padron_id');
        DB::table('representacion_miembros')->where('padron_id', $p[$representado])->update(['grupo_id' => $g[$p[$cabeza]], 'es_cabeza' => 0]);
        DB::table('representacion_grupos')->where('id', $g[$p[$representado]])->delete();
    }

    private function poderNominal(int $e, string $cabeza, string $representado): void
    {
        $p = DB::table('evento_personas')->where('evento_id', $e)->pluck('id', 'cedula');
        $g = DB::table('representacion_grupos_nominal')->where('evento_id', $e)->pluck('id', 'cabeza_persona_id');
        DB::table('representacion_miembros_nominal')->where('persona_id', $p[$representado])->update(['grupo_id' => $g[$p[$cabeza]], 'es_cabeza' => 0]);
        DB::table('representacion_grupos_nominal')->where('id', $g[$p[$representado]])->delete();
    }

    private function registro(int $e, string $inmueble, ?int $control, float $coef, string $estado, array $extra = []): void
    {
        $p = DB::table('evento_padron')->where('evento_id', $e)->where('inmueble', $inmueble)->value('id');
        $g = DB::table('representacion_grupos')->where('cabeza_padron_id', $p)->value('id');
        $c = $control ? DB::table('controles')->where('evento_id', $e)->where('numero', $control)->value('id') : null;
        $r = DB::table('registros_checkin')->insertGetId($extra + [
            'evento_id' => $e, 'grupo_id' => $g, 'inmueble_base_id' => $p, 'estado' => $estado, 'asistente_telefono' => '3000000000',
            'control_id' => $c, 'control_numero_snapshot' => $control, 'coef_total_snapshot' => $coef, 'checked_in_at' => now(),
            'retirado_at' => $estado === 'RETIRADO' ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($c && $estado === 'CHECKED_IN') {
            DB::table('controles')->where('id', $c)->update(['estado' => 'ASIGNADO', 'asignado_a_registro_id' => $r]);
        }
        if ($estado !== 'EN_PROCESO') {
            DB::table('representacion_grupos')->where('id', $g)->update(['checkin_at' => now()]);
        }
    }

    private function registroNominal(int $e, string $cedula, float $votos): void
    {
        $p = DB::table('evento_personas')->where('evento_id', $e)->where('cedula', $cedula)->value('id');
        $g = DB::table('representacion_grupos_nominal')->where('cabeza_persona_id', $p)->value('id');
        DB::table('registros_checkin')->insert([
            'evento_id' => $e, 'grupo_id' => $g, 'inmueble_base_id' => null, 'persona_id' => $p, 'estado' => 'CHECKED_IN', 'asistente_telefono' => '3000000000',
            'control_numero_snapshot' => 1, 'coef_total_snapshot' => $votos, 'checked_in_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('representacion_grupos_nominal')->where('id', $g)->update(['checkin_at' => now()]);
    }

    /** Siembra exactamente una señal de actividad (sin las demás). */
    private function sembrarSenal(int $e, string $senal, bool $nominal): void
    {
        $registroSolo = function (string $estado, array $extra = []) use ($e, $nominal) {
            $p = $nominal ? DB::table('evento_personas')->where('evento_id', $e)->value('id') : DB::table('evento_padron')->where('evento_id', $e)->value('id');
            DB::table('registros_checkin')->insert($extra + [
                'evento_id' => $e, 'grupo_id' => null, 'inmueble_base_id' => $nominal ? null : $p, 'persona_id' => $nominal ? $p : null,
                'estado' => $estado, 'asistente_telefono' => '3000000000', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        match ($senal) {
            'asistencia_en_proceso' => $registroSolo('EN_PROCESO'),
            'asistencia_registrada' => $registroSolo('CHECKED_IN', ['checked_in_at' => now()]),
            'retiro' => $registroSolo('RETIRADO', ['checked_in_at' => now(), 'retirado_at' => now()]),
            'reingreso' => $registroSolo('CHECKED_IN', ['checked_in_at' => now(), 'retirado_at' => now(), 'reingreso_at' => now()]),
            'poder_coeficiente' => $this->poderCoef($e, '101', '102'),
            'poder_nominal' => $this->poderNominal($e, '111', '222'),
            'grupo_con_checkin' => DB::table('representacion_grupos')->where('evento_id', $e)->limit(1)->update(['checkin_at' => now()]),
            'grupo_con_control' => DB::table('representacion_grupos')->where('evento_id', $e)->limit(1)->update(['control_numero' => '1']),
            'grupo_nominal_con_checkin' => DB::table('representacion_grupos_nominal')->where('evento_id', $e)->limit(1)->update(['checkin_at' => now()]),
            'control_asignado' => DB::table('controles')->where('evento_id', $e)->where('numero', 1)->update(['estado' => 'ASIGNADO']),
            'control_con_registro' => DB::table('controles')->where('evento_id', $e)->where('numero', 1)->update(['asignado_a_registro_id' => 999]),
        };
    }

    private function quorum(int $e): array
    {
        session(['active_event_id' => $e]);
        $q = Livewire::test(Quorum::class);

        return ['quorumActual' => $q->get('quorumActual'), 'quorumRetirado' => $q->get('quorumRetirado'),
            'personasCheckin' => $q->get('personasCheckin'), 'controlesActivos' => $q->get('controlesActivos')];
    }

    /** Todas las filas de las tablas de la asamblea (o de un evento), sin ids si $sinIds. */
    private function foto(bool $sinIds = false, ?int $soloEvento = null): array
    {
        $tablas = ['evento_padron', 'evento_personas', 'representacion_grupos', 'representacion_miembros',
            'representacion_grupos_nominal', 'representacion_miembros_nominal', 'controles', 'registros_checkin'];
        $foto = [];
        foreach ($tablas as $t) {
            $foto[$t] = DB::table($t)->when($soloEvento, fn ($q) => $q->where('evento_id', $soloEvento))->orderBy('id')->get()
                ->map(fn ($r) => $sinIds ? array_diff_key((array) $r, array_flip(['id', 'grupo_id', 'padron_id', 'persona_id', 'cabeza_padron_id', 'cabeza_persona_id'])) : (array) $r)
                ->all();
        }

        return $foto;
    }
}
