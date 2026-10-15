<?php

namespace Tests\Feature;

use App\Livewire\Event\Form as EventoForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regresión de las importaciones (COEFIX 2.0, Etapa 1H): base por coeficiente, base nominal,
 * controles y el flujo de creación de evento (auto-importación + auditoría).
 *
 * Los resultados de referencia (tests/Fixtures/importaciones/{motor}/*.json) se generaron con el
 * código anterior a la optimización. Para regenerarlos: COEFIX_ACTUALIZAR_REFERENCIA=1.
 *
 * Cada escenario guarda código de salida, mensajes del comando (o la excepción) y una foto
 * normalizada de las tablas: los ids se sustituyen por claves naturales (inmueble, cédula,
 * número) conservando el orden de inserción, porque los autoincrementales dependen del motor
 * y del orden de las pruebas.
 */
class ImportacionesTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCIA = __DIR__ . '/../Fixtures/importaciones';

    private string $disco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');

        $this->disco = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coefix_import_' . uniqid();
        File::ensureDirectoryExists($this->disco);
        config([
            'filesystems.disks.local.root' => $this->disco,
            'filesystems.disks.private.root' => $this->disco,
            // En pruebas Livewire sube los archivos al disco 'tmp-for-tests' y guarda ahí los storeAs();
            // definirlo sobre la misma carpeta reproduce producción (disco temporal = disco por defecto)
            // y evita que Livewire lo falsee en storage/framework/testing.
            'filesystems.disks.tmp-for-tests' => ['driver' => 'local', 'root' => $this->disco],
            'excel.temporary_files.local_path' => $this->disco,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->disco);
        parent::tearDown();
    }

    public static function escenarios(): array
    {
        $nombres = [
            'coef_valida', 'coef_suma_100_05', 'coef_suma_99_90', 'coef_suma_100_15', 'coef_suma_99_85',
            'coef_inmueble_duplicado', 'coef_falta_columna', 'coef_wipe_con_poderes', 'coef_sin_wipe_con_grupos',
            'coef_sin_wipe_evento_vacio', 'coef_evento_activo', 'coef_lote_grande',
            'nominal_valida', 'nominal_cedula_duplicada', 'nominal_evento_no_nominal', 'nominal_falta_columna',
            'nominal_wipe_con_poderes', 'nominal_sin_wipe_agrega', 'nominal_sin_wipe_con_poderes', 'nominal_sin_wipe_cedula_existente', 'nominal_lote_grande',
            'controles_validos', 'controles_numero_duplicado', 'controles_serial_duplicado', 'controles_numero_invalido',
            'controles_serial_vacio', 'controles_falta_columna', 'controles_wipe', 'controles_sin_wipe_existente',
            'controles_lote_grande',
            'formulario_coeficiente', 'formulario_nominal',
        ];

        return array_combine($nombres, array_map(fn ($n) => [$n], $nombres));
    }

    #[DataProvider('escenarios')]
    public function test_importacion_coincide_con_la_referencia(string $escenario): void
    {
        $actual = json_decode(json_encode($this->{'esc_' . $escenario}(), JSON_PRESERVE_ZERO_FRACTION), true);

        $dir = self::REFERENCIA . '/' . DB::getDriverName();
        $archivo = "{$dir}/{$escenario}.json";

        if (getenv('COEFIX_ACTUALIZAR_REFERENCIA')) {
            File::ensureDirectoryExists($dir);
            file_put_contents($archivo, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
        }

        $this->assertFileExists($archivo);
        $this->assertSame(json_decode(file_get_contents($archivo), true), $actual, "Resultado distinto en {$escenario}");
    }

    public function test_base_que_suma_100_05_se_acepta_con_un_grupo_por_inmueble(): void
    {
        $r = $this->esc_coef_suma_100_05();
        $this->assertSame(0, $r['pasos'][0]['exit'], 'tolerancia ±0,10');
        $this->assertSame(3, $r['foto']['conteos']['representacion_grupos']);
        foreach ($r['foto']['representacion_miembros'] as $m) {
            $this->assertSame($m['grupo'], $m['padron'], 'cada inmueble es la cabeza de su propio grupo');
            $this->assertSame(1, $m['es_cabeza']);
        }
    }

    public function test_base_que_suma_100_15_se_rechaza_sin_escribir(): void
    {
        $r = $this->esc_coef_suma_100_15();
        $this->assertSame(1, $r['pasos'][0]['exit']);
        $this->assertSame(0, $r['foto']['conteos']['evento_padron']);
    }

    public function test_error_a_mitad_de_importacion_no_deja_nada_a_medias(): void
    {
        $r = $this->esc_coef_sin_wipe_con_grupos();
        $this->assertSame('UniqueConstraintViolationException', $r['pasos'][0]['excepcion'] ?? null, 'el grupo existente choca con el único (evento, cabeza)');
        $this->assertSame(2, $r['foto']['conteos']['evento_padron'], 'la transacción deja la base como estaba');
        $this->assertSame(1, $r['foto']['conteos']['representacion_grupos']);
    }

    public function test_nominal_sin_wipe_solo_crea_grupos_faltantes(): void
    {
        $r = $this->esc_nominal_sin_wipe_agrega();
        $this->assertSame(0, $r['pasos'][0]['exit']);
        $this->assertSame(5, $r['foto']['conteos']['representacion_grupos_nominal'], '3 existentes + 2 nuevos');
        $this->assertSame(5, $r['foto']['conteos']['representacion_miembros_nominal']);
    }

    public function test_nominal_sin_wipe_con_poderes_falla_sin_cambios(): void
    {
        // Comportamiento existente: quien está representado por poder no es cabeza de ningún grupo,
        // así que el importador intenta crearle un grupo base y choca con el único (evento, persona).
        $r = $this->esc_nominal_sin_wipe_con_poderes();
        $this->assertSame('UniqueConstraintViolationException', $r['pasos'][0]['excepcion'] ?? null);
        $this->assertSame(3, $r['foto']['conteos']['evento_personas'], 'la transacción deja la base como estaba');
    }

    // ── Escenarios: base por coeficiente ────────────────────────────

    private const BASE_ENCABEZADO = ['Inmueble', 'Propietario', 'Coeficiente', 'Asistente', 'Celular', 'Correo'];

    private function esc_coef_valida(): array
    {
        $e = $this->evento('Coef valida', 'coeficiente', ['base_excel_path' => $this->xlsx('archivos/a/base.xlsx', [
            ['Referencia', 'Dueño', 'Coef', 'Representante', 'Teléfono', 'E-mail'],
            ['T1-101', 'Ana Pérez', 20.5, 'Luis', '3001112233', 'luis@ejemplo.test'],
            ['  T1-102 ', ' Bruno Díaz ', '15,25', '', null, ''],
            [null, null, null, null, null, null],
            ['T1-103', 'Carla Ruiz', '0,25', 'Marta', '', 'marta@ejemplo.test'],
            ['T1-104', '', 10, null, null, null],
            ['T2-101', 'Diego Gómez', 64, null, '3109998877', null],
            ['T2-102', 'Elena Mora', null, null, null, null],
        ])]);

        return ['pasos' => [$this->comando('base:import', ['--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_coef_suma_100_05(): array
    {
        return $this->baseConSuma([40.02, 30.02, 30.01]);
    }

    private function esc_coef_suma_99_90(): array
    {
        return $this->baseConSuma([40, 30, 29.9]);
    }

    private function esc_coef_suma_100_15(): array
    {
        return $this->baseConSuma([40.05, 30.05, 30.05]);
    }

    private function esc_coef_suma_99_85(): array
    {
        return $this->baseConSuma([40, 30, 29.85]);
    }

    private function baseConSuma(array $coefs): array
    {
        $filas = [self::BASE_ENCABEZADO];
        foreach ($coefs as $i => $c) {
            $filas[] = ['A-' . ($i + 1), 'Propietario ' . ($i + 1), $c];
        }
        $this->evento('Coef suma', 'coeficiente', ['base_excel_path' => $this->xlsx('archivos/a/base.xlsx', $filas)]);

        return ['pasos' => [$this->comando('base:import', ['--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_coef_inmueble_duplicado(): array
    {
        $this->evento('Coef duplicado', 'coeficiente', ['base_excel_path' => $this->xlsx('archivos/a/base.xlsx', [
            self::BASE_ENCABEZADO, ['T1-101', 'Ana', 50], ['t1-101', 'Bruno', 50],
        ])]);

        return ['pasos' => [$this->comando('base:import', ['--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_coef_falta_columna(): array
    {
        $this->evento('Coef sin columna', 'coeficiente', ['base_excel_path' => $this->xlsx('archivos/a/base.xlsx', [
            ['Inmueble', 'Propietario', 'Valor'], ['T1-101', 'Ana', 100],
        ])]);

        return ['pasos' => [$this->comando('base:import', ['--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_coef_wipe_con_poderes(): array
    {
        // Evento ya usado: grupo con poder (T1-101 representa a T1-102), controles y un registro.
        // Hay otro evento anterior con su propia base, que no debe tocarse.
        $otro = $this->evento('Otro evento', 'coeficiente');
        $e = $this->evento('Coef wipe', 'coeficiente', ['base_excel_path' => $this->xlsx('archivos/a/base.xlsx', [
            self::BASE_ENCABEZADO, ['N-1', 'Nuevo Uno', 60], ['N-2', 'Nuevo Dos', 40],
        ])]);
        $this->padronConPoder($e);
        $this->padronConPoder($otro);
        DB::table('controles')->insert(['evento_id' => $e, 'numero' => 1, 'serial' => 'S-1', 'estado' => 'ASIGNADO']);

        return ['pasos' => [$this->comando('base:import', ['--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_coef_sin_wipe_con_grupos(): array
    {
        $e = $this->evento('Coef sin wipe', 'coeficiente', ['base_excel_path' => $this->xlsx('archivos/a/base.xlsx', [
            self::BASE_ENCABEZADO, ['N-1', 'Nuevo Uno', 60], ['N-2', 'Nuevo Dos', 40],
        ])]);
        $p1 = DB::table('evento_padron')->insertGetId(['evento_id' => $e, 'inmueble' => 'V-1', 'propietario' => 'Viejo Uno', 'coeficiente' => 50]);
        DB::table('evento_padron')->insert(['evento_id' => $e, 'inmueble' => 'V-2', 'propietario' => 'Viejo Dos', 'coeficiente' => 50]);
        $g = DB::table('representacion_grupos')->insertGetId(['evento_id' => $e, 'cabeza_padron_id' => $p1]);
        DB::table('representacion_miembros')->insert(['evento_id' => $e, 'grupo_id' => $g, 'padron_id' => $p1, 'es_cabeza' => 1]);

        return ['pasos' => [$this->comando('base:import')], 'foto' => $this->foto()];
    }

    private function esc_coef_sin_wipe_evento_vacio(): array
    {
        $this->evento('Coef vacio', 'coeficiente', ['base_excel_path' => $this->xlsx('archivos/a/base.xlsx', [
            self::BASE_ENCABEZADO, ['N-1', 'Nuevo Uno', 60], ['N-2', 'Nuevo Dos', 40],
        ])]);

        return ['pasos' => [$this->comando('base:import')], 'foto' => $this->foto()];
    }

    private function esc_coef_evento_activo(): array
    {
        // base:import usa el evento is_active=1 aunque haya otro más reciente.
        $this->evento('Coef activo', 'coeficiente', ['is_active' => 1, 'base_excel_path' => $this->xlsx('archivos/a/base.xlsx', [
            self::BASE_ENCABEZADO, ['A-1', 'Activo Uno', 100],
        ])]);
        $this->evento('Coef reciente', 'coeficiente', ['base_excel_path' => $this->xlsx('archivos/b/base.xlsx', [
            self::BASE_ENCABEZADO, ['R-1', 'Reciente Uno', 100],
        ])]);

        return ['pasos' => [$this->comando('base:import', ['--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_coef_lote_grande(): array
    {
        // 1201 filas: cruza los lotes de 500 y suma exactamente 100 (1200 × 0,0833 + 0,04).
        $filas = [self::BASE_ENCABEZADO];
        for ($i = 1; $i <= 1200; $i++) {
            $filas[] = [sprintf('L-%04d', $i), "Propietario {$i}", 0.0833, $i % 7 === 0 ? "Asistente {$i}" : null];
        }
        $filas[] = ['L-1201', 'Propietario 1201', 0.04];
        $this->evento('Coef grande', 'coeficiente', ['base_excel_path' => $this->xlsx('archivos/a/base.xlsx', $filas)]);

        return ['pasos' => [$this->comando('base:import', ['--wipe' => true])], 'foto' => $this->foto(true)];
    }

    // ── Escenarios: base nominal ────────────────────────────────────

    private function esc_nominal_valida(): array
    {
        $this->evento('Nominal valida', 'nominal', ['personas_excel_path' => $this->xlsx('archivos/a/personas.xlsx', [
            ['Documento', 'Nombre completo', 'Celular', 'Email'],
            ['1.234.567', 'Ana Pérez', '3001112233', 'ana@ejemplo.test'],
            [' 89 012 345 ', 'Bruno Díaz', '', null],
            [null, null, null, null],
            ['', 'Sin Cédula', null, null],
            ['CE-778,1', 'Carla Ruiz', null, 'carla@ejemplo.test'],
            [123456, 'Diego Gómez', 3109998877, null],
        ])]);

        return ['pasos' => [$this->comando('base:import-nominal', ['--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_nominal_cedula_duplicada(): array
    {
        $this->evento('Nominal duplicada', 'nominal', ['personas_excel_path' => $this->xlsx('archivos/a/personas.xlsx', [
            ['Cedula', 'Nombre'], ['1.234.567', 'Ana'], ['1234567', 'Ana Otra'],
        ])]);

        return ['pasos' => [$this->comando('base:import-nominal', ['--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_nominal_evento_no_nominal(): array
    {
        $this->evento('Nominal no', 'coeficiente', ['personas_excel_path' => $this->xlsx('archivos/a/personas.xlsx', [
            ['Cedula', 'Nombre'], ['111', 'Ana'],
        ])]);

        return ['pasos' => [$this->comando('base:import-nominal', ['--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_nominal_falta_columna(): array
    {
        $this->evento('Nominal sin columna', 'nominal', ['personas_excel_path' => $this->xlsx('archivos/a/personas.xlsx', [
            ['Cedula', 'Apellido'], ['111', 'Pérez'],
        ])]);

        return ['pasos' => [$this->comando('base:import-nominal', ['--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_nominal_wipe_con_poderes(): array
    {
        $e = $this->evento('Nominal wipe', 'nominal', ['personas_excel_path' => $this->xlsx('archivos/a/personas.xlsx', [
            ['Cedula', 'Nombre'], ['900', 'Nueva Uno'], ['111', 'Ana Reimportada'],
        ])]);
        $per = $this->personasConPoder($e);
        DB::table('registros_checkin')->insert([
            'evento_id' => $e, 'grupo_id' => 0, 'inmueble_base_id' => null, 'persona_id' => $per['111'],
            'estado' => 'CHECKED_IN', 'control_numero_snapshot' => 5, 'coef_total_snapshot' => 2, 'asistente_telefono' => '3000000000',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['pasos' => [$this->comando('base:import-nominal', ['--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_nominal_sin_wipe_agrega(): array
    {
        $e = $this->evento('Nominal agrega', 'nominal', ['personas_excel_path' => $this->xlsx('archivos/a/personas.xlsx', [
            ['Cedula', 'Nombre'], ['900', 'Nueva Uno'], ['901', 'Nueva Dos'],
        ])]);
        $this->personasConPoder($e, false);

        return ['pasos' => [$this->comando('base:import-nominal')], 'foto' => $this->foto()];
    }

    private function esc_nominal_sin_wipe_con_poderes(): array
    {
        $e = $this->evento('Nominal agrega con poderes', 'nominal', ['personas_excel_path' => $this->xlsx('archivos/a/personas.xlsx', [
            ['Cedula', 'Nombre'], ['900', 'Nueva Uno'], ['901', 'Nueva Dos'],
        ])]);
        $this->personasConPoder($e);

        return ['pasos' => [$this->comando('base:import-nominal')], 'foto' => $this->foto()];
    }

    private function esc_nominal_sin_wipe_cedula_existente(): array
    {
        $e = $this->evento('Nominal existente', 'nominal', ['personas_excel_path' => $this->xlsx('archivos/a/personas.xlsx', [
            ['Cedula', 'Nombre'], ['900', 'Nueva Uno'], ['111', 'Ana Repetida'],
        ])]);
        $this->personasConPoder($e);

        return ['pasos' => [$this->comando('base:import-nominal')], 'foto' => $this->foto()];
    }

    private function esc_nominal_lote_grande(): array
    {
        $filas = [['Cedula', 'Nombre', 'Telefono']];
        for ($i = 1; $i <= 1201; $i++) {
            $filas[] = [(string) (10000000 + $i), "Persona {$i}", $i % 5 === 0 ? "300{$i}" : null];
        }
        $this->evento('Nominal grande', 'nominal', ['personas_excel_path' => $this->xlsx('archivos/a/personas.xlsx', $filas)]);

        return ['pasos' => [$this->comando('base:import-nominal', ['--wipe' => true])], 'foto' => $this->foto(true)];
    }

    // ── Escenarios: controles ───────────────────────────────────────

    private function controlesEvento(array $filas): int
    {
        return $this->evento('Controles', 'coeficiente', ['controles_excel_path' => $this->xlsx('archivos/a/controles.xlsx', $filas)]);
    }

    private function esc_controles_validos(): array
    {
        $e = $this->controlesEvento([
            ['Numero_control', 'Codigo_control'], [1, 'ABC-001'], ['2', ' abc-002 '], [null, null], [10, 'X10'],
        ]);

        return ['pasos' => [$this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_controles_numero_duplicado(): array
    {
        $e = $this->controlesEvento([['Numero', 'Serial'], [1, 'A'], [1, 'B']]);

        return ['pasos' => [$this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_controles_serial_duplicado(): array
    {
        $e = $this->controlesEvento([['Numero', 'Codigo'], [1, 'Serie-A'], [2, 'serie-a']]);

        return ['pasos' => [$this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_controles_numero_invalido(): array
    {
        $e = $this->controlesEvento([['Numero', 'Codigo'], [1, 'A'], ['12a', 'B']]);

        return ['pasos' => [$this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_controles_serial_vacio(): array
    {
        $e = $this->controlesEvento([['Numero', 'Codigo'], [1, 'A'], [2, '']]);

        return ['pasos' => [$this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_controles_falta_columna(): array
    {
        $e = $this->controlesEvento([['Numero', 'Color'], [1, 'Rojo']]);

        return ['pasos' => [$this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_controles_wipe(): array
    {
        $e = $this->controlesEvento([['Numero', 'Codigo'], [1, 'NUEVO-1'], [2, 'NUEVO-2']]);
        $otro = $this->evento('Otro', 'coeficiente');
        DB::table('controles')->insert([
            ['evento_id' => $e, 'numero' => 1, 'serial' => 'VIEJO-1', 'estado' => 'ASIGNADO'],
            ['evento_id' => $otro, 'numero' => 1, 'serial' => 'OTRO-1', 'estado' => 'LIBRE'],
        ]);

        return ['pasos' => [$this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true])], 'foto' => $this->foto()];
    }

    private function esc_controles_sin_wipe_existente(): array
    {
        $e = $this->controlesEvento([['Numero', 'Codigo'], [5, 'NUEVO-5'], [1, 'NUEVO-1']]);
        DB::table('controles')->insert(['evento_id' => $e, 'numero' => 1, 'serial' => 'VIEJO-1', 'estado' => 'LIBRE']);

        return ['pasos' => [$this->comando('controls:import-excel', ['evento_id' => $e])], 'foto' => $this->foto()];
    }

    private function esc_controles_lote_grande(): array
    {
        $filas = [['Numero_control', 'Codigo_control']];
        for ($i = 1; $i <= 1201; $i++) {
            $filas[] = [$i, sprintf('C%05d', $i)];
        }
        $e = $this->controlesEvento($filas);

        return ['pasos' => [$this->comando('controls:import-excel', ['evento_id' => $e, '--wipe' => true])], 'foto' => $this->foto(true)];
    }

    // ── Escenarios: formulario de creación (auto-importación + auditoría) ─

    private function esc_formulario_coeficiente(): array
    {
        $base = $this->xlsxBytes([self::BASE_ENCABEZADO, ['T1-101', 'Ana', 55.5], ['T1-102', 'Bruno', '44,52']]);
        $controles = $this->xlsxBytes([['Numero_control', 'Codigo_control'], [1, 'F-1'], [2, 'F-2']]);

        return $this->crearEventoPorFormulario('coeficiente', ['baseExcelFile' => $base, 'controlesExcelFile' => $controles]);
    }

    private function esc_formulario_nominal(): array
    {
        $personas = $this->xlsxBytes([['Cedula', 'Nombre'], ['1.000', 'Ana'], ['2.000', 'Bruno'], ['1000', 'Ana Duplicada']]);
        $controles = $this->xlsxBytes([['Numero_control', 'Codigo_control'], [1, 'F-1']]);

        return $this->crearEventoPorFormulario('nominal', ['personasExcelFile' => $personas, 'controlesExcelFile' => $controles]);
    }

    private function crearEventoPorFormulario(string $tipo, array $archivos): array
    {
        Role::create(['name' => 'ADMIN']);
        $admin = User::factory()->create(['email' => 'admin@ejemplo.test']);
        $admin->assignRole('ADMIN');
        $this->actingAs($admin);

        $form = Livewire::test(EventoForm::class)
            ->set('titulo', "Asamblea {$tipo}")
            ->set('fecha_inicio', '2026-10-20')
            ->set('tipoQuorum', $tipo);
        foreach ($archivos as $campo => $bytes) {
            $form->set($campo, UploadedFile::fake()->createWithContent("{$campo}.xlsx", $bytes));
        }
        $form->call('save')->assertHasNoErrors()->assertRedirect(route('eventos.index'));

        $auditoria = DB::table('audit_logs')->orderBy('id')->get()->map(fn ($a) => [
            // Los mensajes llevan saltos PHP_EOL (\r\n en Windows); se normalizan para que la referencia sirva en cualquier sistema.
            'modulo' => $a->modulo, 'accion' => $a->accion, 'meta' => json_decode($this->sinIds(str_replace('\r\n', '\n', $a->meta)), true),
        ])->all();

        return ['pasos' => [['flash' => session('ok')]], 'auditoria' => $auditoria, 'foto' => $this->foto()];
    }

    // ── Apoyo ───────────────────────────────────────────────────────

    private function comando(string $nombre, array $args = []): array
    {
        try {
            $exit = Artisan::call($nombre, $args);

            return ['comando' => $nombre, 'exit' => $exit, 'salida' => $this->sinIds(str_replace([$this->disco, "\r\n"], ['<disco>', "\n"], trim(Artisan::output())))];
        } catch (\Throwable $e) {
            // El SQL que falla puede diferir; lo que importa es el tipo de error y que no quede nada a medias.
            return ['comando' => $nombre, 'excepcion' => class_basename($e), 'codigo' => (string) $e->getCode()];
        }
    }

    /** "#57", "evento 57", "eventos/57/" → "#E2", "evento E2", "eventos/E2/" (posición del evento en esta prueba; en MySQL los ids no se reinician). */
    private function sinIds(string $texto): string
    {
        $posicion = DB::table('eventos')->orderBy('id')->pluck('id')->flip();

        return preg_replace_callback('~(#|evento |eventos.?/)(\d+)~i', fn ($m) => $m[1] . (isset($posicion[(int) $m[2]]) ? 'E' . ($posicion[(int) $m[2]] + 1) : $m[2]), $texto);
    }

    private function evento(string $titulo, string $tipo, array $extra = []): int
    {
        static $n = 0;
        $n++;

        return DB::table('eventos')->insertGetId($extra + [
            'titulo' => $titulo, 'slug' => 'evento-' . $n . '-' . uniqid(), 'fecha_inicio' => '2026-10-15',
            'activo' => 1, 'is_active' => 0, 'tipo_quorum' => $tipo, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function padronConPoder(int $e): void
    {
        $p = [];
        foreach (['T1-101' => 50, 'T1-102' => 30, 'T1-103' => 20] as $inm => $c) {
            $p[$inm] = DB::table('evento_padron')->insertGetId(['evento_id' => $e, 'inmueble' => $inm, 'propietario' => "Prop {$inm}", 'coeficiente' => $c]);
        }
        $g1 = DB::table('representacion_grupos')->insertGetId(['evento_id' => $e, 'cabeza_padron_id' => $p['T1-101'], 'control_numero' => '1', 'control_serial' => 'S-1']);
        DB::table('representacion_miembros')->insert([
            ['evento_id' => $e, 'grupo_id' => $g1, 'padron_id' => $p['T1-101'], 'es_cabeza' => 1],
            ['evento_id' => $e, 'grupo_id' => $g1, 'padron_id' => $p['T1-102'], 'es_cabeza' => 0],
        ]);
        $g3 = DB::table('representacion_grupos')->insertGetId(['evento_id' => $e, 'cabeza_padron_id' => $p['T1-103']]);
        DB::table('representacion_miembros')->insert(['evento_id' => $e, 'grupo_id' => $g3, 'padron_id' => $p['T1-103'], 'es_cabeza' => 1]);
        DB::table('registros_checkin')->insert([
            'evento_id' => $e, 'grupo_id' => $g1, 'inmueble_base_id' => $p['T1-101'], 'estado' => 'CHECKED_IN',
            'control_numero_snapshot' => 1, 'coef_total_snapshot' => 80, 'asistente_telefono' => '3000000000', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Personas 111, 222 y 333 con sus grupos base; con $poder, 111 representa a 222. */
    private function personasConPoder(int $e, bool $poder = true): array
    {
        $per = [];
        foreach (['111' => 'Ana', '222' => 'Bruno', '333' => 'Carla'] as $ced => $nom) {
            $per[$ced] = DB::table('evento_personas')->insertGetId(['evento_id' => $e, 'cedula' => $ced, 'nombre' => $nom]);
        }
        $cabezas = $poder ? ['111' => ['111', '222'], '333' => ['333']] : ['111' => ['111'], '222' => ['222'], '333' => ['333']];
        foreach ($cabezas as $cabeza => $miembros) {
            $g = DB::table('representacion_grupos_nominal')->insertGetId(['evento_id' => $e, 'cabeza_persona_id' => $per[$cabeza]]);
            foreach ($miembros as $m) {
                DB::table('representacion_miembros_nominal')->insert(['evento_id' => $e, 'grupo_id' => $g, 'persona_id' => $per[$m], 'es_cabeza' => (int) ($m === $cabeza)]);
            }
        }

        return $per;
    }

    private function xlsx(string $relativa, array $filas): string
    {
        $ruta = $this->disco . '/' . $relativa;
        File::ensureDirectoryExists(dirname($ruta));
        file_put_contents($ruta, $this->xlsxBytes($filas));

        return $relativa;
    }

    private function xlsxBytes(array $filas): string
    {
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray($filas, null, 'A1', true);
        $tmp = tempnam($this->disco, 'xlsx');
        (new Xlsx($libro))->save($tmp);
        $bytes = file_get_contents($tmp);
        unlink($tmp);

        return $bytes;
    }

    /**
     * Foto normalizada de todas las tablas que tocan las importaciones, en orden de id.
     * Con $resumen, las filas se reducen a conteos y un hash (lotes grandes).
     */
    private function foto(bool $resumen = false): array
    {
        $ev = DB::table('eventos')->pluck('titulo', 'id');
        $inm = DB::table('evento_padron')->pluck('inmueble', 'id');
        $ced = DB::table('evento_personas')->pluck('cedula', 'id');
        $cabezaGrupo = DB::table('representacion_grupos')->pluck('cabeza_padron_id', 'id')->map(fn ($p) => $inm[$p] ?? "#{$p}");
        $cabezaGrupoN = DB::table('representacion_grupos_nominal')->pluck('cabeza_persona_id', 'id')->map(fn ($p) => $ced[$p] ?? "#{$p}");
        $ts = fn ($r) => ['created_at' => $r->created_at, 'updated_at' => $r->updated_at];
        $filas = fn (string $tabla, callable $fn) => DB::table($tabla)->orderBy('id')->get()->map($fn)->all();

        $foto = [
            'evento_padron' => $filas('evento_padron', fn ($r) => [
                'evento' => $ev[$r->evento_id], 'inmueble' => $r->inmueble, 'propietario' => $r->propietario, 'coeficiente' => $r->coeficiente,
                'asistente' => $r->asistente, 'celular' => $r->celular_asistente, 'correo' => $r->correo_asistente,
            ] + $ts($r)),
            'representacion_grupos' => $filas('representacion_grupos', fn ($r) => [
                'evento' => $ev[$r->evento_id], 'cabeza' => $inm[$r->cabeza_padron_id] ?? "#{$r->cabeza_padron_id}",
                'control_numero' => $r->control_numero, 'control_serial' => $r->control_serial, 'checkin_at' => $r->checkin_at, 'checkin_by' => $r->checkin_by,
            ] + $ts($r)),
            'representacion_miembros' => $filas('representacion_miembros', fn ($r) => [
                'evento' => $ev[$r->evento_id], 'grupo' => $cabezaGrupo[$r->grupo_id] ?? "#{$r->grupo_id}",
                'padron' => $inm[$r->padron_id] ?? "#{$r->padron_id}", 'es_cabeza' => (int) $r->es_cabeza,
            ] + $ts($r)),
            'evento_personas' => $filas('evento_personas', fn ($r) => [
                'evento' => $ev[$r->evento_id], 'cedula' => $r->cedula, 'nombre' => $r->nombre, 'telefono' => $r->telefono, 'correo' => $r->correo,
            ] + $ts($r)),
            'representacion_grupos_nominal' => $filas('representacion_grupos_nominal', fn ($r) => [
                'evento' => $ev[$r->evento_id], 'cabeza' => $ced[$r->cabeza_persona_id] ?? "#{$r->cabeza_persona_id}",
                'control_numero' => $r->control_numero, 'control_serial' => $r->control_serial, 'checkin_at' => $r->checkin_at,
            ] + $ts($r)),
            'representacion_miembros_nominal' => $filas('representacion_miembros_nominal', fn ($r) => [
                'evento' => $ev[$r->evento_id], 'grupo' => $cabezaGrupoN[$r->grupo_id] ?? "#{$r->grupo_id}",
                'persona' => $ced[$r->persona_id] ?? "#{$r->persona_id}", 'es_cabeza' => (int) $r->es_cabeza,
            ] + $ts($r)),
            'controles' => $filas('controles', fn ($r) => [
                'evento' => $ev[$r->evento_id], 'numero' => (int) $r->numero, 'serial' => $r->serial, 'estado' => $r->estado,
                'asignado_a_registro_id' => $r->asignado_a_registro_id,
            ] + $ts($r)),
            'registros_checkin' => $filas('registros_checkin', fn ($r) => [
                'evento' => $ev[$r->evento_id], 'persona' => $r->persona_id === null ? null : ($ced[$r->persona_id] ?? "#{$r->persona_id}"),
                'inmueble_base' => $r->inmueble_base_id === null ? null : ($inm[$r->inmueble_base_id] ?? 'borrado'),
                'estado' => $r->estado, 'control_numero_snapshot' => $r->control_numero_snapshot,
            ]),
        ];

        $conteos = array_map('count', $foto);
        if ($resumen) {
            return ['conteos' => $conteos, 'sha256' => array_map(fn ($t) => hash('sha256', json_encode($t, JSON_PRESERVE_ZERO_FRACTION)), $foto)];
        }

        return ['conteos' => $conteos] + $foto;
    }
}
