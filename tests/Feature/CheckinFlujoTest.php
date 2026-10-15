<?php

namespace Tests\Feature;

use App\Livewire\Checkin\RegistroPantalla;
use App\Livewire\Controls\RetiroReingreso;
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
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regresión del flujo normal de check-in, poderes, controles, retiro/reingreso/reemplazo y quórum
 * (COEFIX 2.0, Etapa 1I). Las referencias (tests/Fixtures/checkin/{motor}/*.json) se generaron con
 * el código anterior a las correcciones de seguridad, usando solo lo que la pantalla permite hacer
 * (sin manipular el navegador). No incluyen los defectos I1–I8. Regenerar: COEFIX_ACTUALIZAR_REFERENCIA=1.
 */
class CheckinFlujoTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCIA = __DIR__ . '/../Fixtures/checkin';

    private string $disco;
    private int $coef;
    private int $nominal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $this->disco = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coefix_checkin_' . uniqid();
        File::ensureDirectoryExists($this->disco);
        config([
            'filesystems.disks.local.root' => $this->disco,
            'filesystems.disks.private.root' => $this->disco,
            'excel.temporary_files.local_path' => $this->disco,
        ]);

        Role::create(['name' => 'ADMIN']);
        $admin = User::factory()->create(['email' => 'operador@ejemplo.test']);
        $admin->assignRole('ADMIN');
        $this->actingAs($admin);

        $this->coef = $this->evento('Asamblea coeficiente', 'coeficiente',
            [['Inmueble', 'Propietario', 'Coeficiente'], ['101', 'Ana', 30], ['102', 'Bruno', 25], ['103', 'Carla', 20], ['104', 'Diego', 15], ['105', 'Elena', 10]]);
        $this->nominal = $this->evento('Asamblea nominal', 'nominal',
            [['Cedula', 'Nombre'], ['111', 'Ana'], ['222', 'Bruno'], ['333', 'Carla'], ['444', 'Diana']]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->disco);
        parent::tearDown();
    }

    public static function escenarios(): array
    {
        $n = ['c_seleccionar', 'c_poder_y_guardar', 'c_abrir_representado', 'c_poder_bloqueado', 'c_quitar_poder',
            'c_quitar_poder_tras_checkin', 'c_agregar_poder_tras_checkin', 'c_control_ocupado', 'c_validaciones_guardar',
            'c_separar_cabeza', 'r_retiro_reingreso', 'r_reemplazo', 'r_consulta', 'r_errores',
            'n_poder_y_guardar', 'n_quitar_poder', 'n_retiro_reingreso'];

        return array_combine($n, array_map(fn ($x) => [$x], $n));
    }

    #[DataProvider('escenarios')]
    public function test_flujo_coincide_con_la_referencia(string $escenario): void
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

    // ── Coeficiente ─────────────────────────────────────────────────

    private function esc_c_seleccionar(): array
    {
        $c = $this->checkin($this->coef);
        $c->set('search', '10');
        $pasos = ['buscar' => $this->sinIds($c->get('results'))];
        $c->call('requestSelectInmueble', $this->id('101'));
        $pasos['seleccionar 101'] = $this->estado($c);

        return $this->resultado($pasos);
    }

    private function esc_c_poder_y_guardar(): array
    {
        $c = $this->checkin($this->coef);
        $pasos = $this->registrarConPoder($c);

        return $this->resultado($pasos, quorum: true);
    }

    private function esc_c_abrir_representado(): array
    {
        $this->registrarConPoder($this->checkin($this->coef));
        $c = $this->checkin($this->coef)->call('requestSelectInmueble', $this->id('102'));

        return $this->resultado(['abrir 102 (representado por 101)' => $this->estado($c)]);
    }

    private function esc_c_poder_bloqueado(): array
    {
        $this->registrarConPoder($this->checkin($this->coef));
        $c = $this->checkin($this->coef)->call('requestSelectInmueble', $this->id('103'));
        $c->call('addPoder', $this->id('102'));
        $pasos = ['anexar 102 (ya en el grupo de 101)' => $this->estado($c)];
        $c->call('closeControlModal')->call('addPoder', $this->id('101'));
        $pasos['anexar 101 (ya registrado)'] = $this->estado($c);

        return $this->resultado($pasos);
    }

    private function esc_c_quitar_poder(): array
    {
        $c = $this->checkin($this->coef)->call('requestSelectInmueble', $this->id('101'));
        $c->set('poderSearch', '102')->call('addPoder', $this->id('102'));
        $pasos = ['anexar' => $this->estado($c)];
        $c->call('removePoder', $this->miembro('102'));
        $pasos['quitar 102'] = $this->estado($c);

        return $this->resultado($pasos);
    }

    private function esc_c_quitar_poder_tras_checkin(): array
    {
        $c = $this->checkin($this->coef);
        $this->registrarConPoder($c);
        $c->call('requestSelectInmueble', $this->id('101'))->call('removePoder', $this->miembro('102'));

        return $this->resultado(['quitar 102 tras el check-in' => $this->estado($c)], quorum: true);
    }

    private function esc_c_agregar_poder_tras_checkin(): array
    {
        $c = $this->checkin($this->coef);
        $this->registrarConPoder($c);
        $c->call('requestSelectInmueble', $this->id('101'))->set('poderSearch', '103')->call('addPoder', $this->id('103'));

        return $this->resultado(['anexar 103 tras el check-in' => $this->estado($c)], quorum: true);
    }

    private function esc_c_control_ocupado(): array
    {
        $this->registrarConPoder($this->checkin($this->coef));
        $c = $this->checkin($this->coef)->call('requestSelectInmueble', $this->id('103'));
        $c->set('controlNumeroInput', '1');
        $pasos = ['vista previa control 1' => $this->estado($c)];
        $c->call('confirmControl');
        $pasos['confirmar control 1 (ocupado)'] = $this->estado($c);
        $c->call('closeControlModal')->set('controlNumeroInput', '9')->call('confirmControl');
        $pasos['confirmar control 9 (no existe)'] = $this->estado($c);

        return $this->resultado($pasos);
    }

    private function esc_c_validaciones_guardar(): array
    {
        $c = $this->checkin($this->coef)->call('requestSelectInmueble', $this->id('104'));
        $c->set('asistenteTelefono', '')->call('saveAsistente');
        $pasos = ['guardar sin teléfono' => $this->estado($c)];
        $c->set('asistenteTelefono', '3001112233')->call('saveAsistente');
        $pasos['guardar sin control preparado'] = $this->estado($c) + ['errores' => $c->instance()->getErrorBag()->toArray()];
        $c->set('controlNumeroInput', '2')->call('confirmControl')->call('saveAsistente');
        $pasos['guardar con control 2'] = $this->estado($c);

        return $this->resultado($pasos, quorum: true);
    }

    private function esc_c_separar_cabeza(): array
    {
        $c = $this->checkin($this->coef);
        $this->registrarConPoder($c);
        $c->call('requestSelectInmueble', $this->id('101'))->call('separarCabeza');

        return $this->resultado(['separar cabeza 101' => $this->estado($c)], quorum: true);
    }

    // ── Retiro, reingreso, reemplazo ────────────────────────────────

    private function esc_r_retiro_reingreso(): array
    {
        $this->registrarConPoder($this->checkin($this->coef));
        $r = $this->retiro($this->coef);
        $r->set('retiroNumero', '1')->call('retirar');
        $pasos = ['retirar 1' => $this->estadoRetiro($r) + ['quorum' => $this->quorum($this->coef)]];
        $r->call('closeModal')->set('reingresoNumero', '1')->call('reingresar');
        $pasos['reingresar 1'] = $this->estadoRetiro($r) + ['quorum' => $this->quorum($this->coef)];

        return $this->resultado($pasos);
    }

    private function esc_r_reemplazo(): array
    {
        $this->registrarConPoder($this->checkin($this->coef));
        $r = $this->retiro($this->coef);
        $r->set('reemplazoNumeroActual', '1')->call('buscarControlActual');
        $pasos = ['buscar actual 1' => $this->estadoRetiro($r)];
        $r->set('reemplazoNumeroNuevo', '3')->call('buscarControlNuevo');
        $pasos['buscar nuevo 3'] = $this->estadoRetiro($r);
        $r->call('reemplazarControl');
        $pasos['reemplazar 1 → 3'] = $this->estadoRetiro($r);

        return $this->resultado($pasos, quorum: true);
    }

    private function esc_r_consulta(): array
    {
        $this->registrarConPoder($this->checkin($this->coef));
        $r = $this->retiro($this->coef);
        $r->set('consultaNumero', '1')->call('consultarControl');

        return $this->resultado(['consultar 1' => $this->estadoRetiro($r)]);
    }

    private function esc_r_errores(): array
    {
        $this->registrarConPoder($this->checkin($this->coef));
        $r = $this->retiro($this->coef);
        $r->set('retiroNumero', '5')->call('retirar');
        $pasos = ['retirar 5 (libre)' => $this->estadoRetiro($r)];
        $r->call('closeModal')->set('reingresoNumero', '1')->call('reingresar');
        $pasos['reingresar 1 (activo)'] = $this->estadoRetiro($r);
        $r->call('closeModal')->set('retiroNumero', '77')->call('retirar');
        $pasos['retirar 77 (no existe)'] = $this->estadoRetiro($r);
        $r->call('closeModal')->set('reemplazoNumeroActual', '1')->call('buscarControlActual')->set('reemplazoNumeroNuevo', '1')->call('reemplazarControl');
        $pasos['reemplazar 1 → 1'] = $this->estadoRetiro($r);

        return $this->resultado($pasos);
    }

    // ── Nominal ─────────────────────────────────────────────────────

    private function esc_n_poder_y_guardar(): array
    {
        $c = $this->checkin($this->nominal);
        $c->set('search', 'An');
        $pasos = ['buscar' => $this->sinIds($c->get('results'))];
        $c->call('requestSelectPersona', $this->idPersona('111'));
        $pasos['seleccionar 111'] = $this->estado($c);
        $c->set('poderSearch', '222')->call('addPoderNominal', $this->idPersona('222'));
        $pasos['anexar 222'] = $this->estado($c);
        $c->set('asistenteTelefono', '3001112233')->set('controlNumeroInput', '1')->call('confirmControl')->call('saveAsistente');
        $pasos['guardar'] = $this->estado($c);
        $c2 = $this->checkin($this->nominal)->call('requestSelectPersona', $this->idPersona('222'));
        $pasos['abrir 222 (representada)'] = $this->estado($c2);

        return $this->resultado($pasos, quorum: true);
    }

    private function esc_n_quitar_poder(): array
    {
        $c = $this->checkin($this->nominal)->call('requestSelectPersona', $this->idPersona('111'));
        $c->set('poderSearch', '222')->call('addPoderNominal', $this->idPersona('222'));
        $c->set('asistenteTelefono', '3001112233')->set('controlNumeroInput', '1')->call('confirmControl')->call('saveAsistente');
        $c->call('requestSelectPersona', $this->idPersona('111'))->call('removePoderNominal', $this->miembroNominal('222'));

        return $this->resultado(['quitar 222 tras el check-in' => $this->estado($c)], quorum: true);
    }

    private function esc_n_retiro_reingreso(): array
    {
        $c = $this->checkin($this->nominal)->call('requestSelectPersona', $this->idPersona('111'));
        $c->set('poderSearch', '222')->call('addPoderNominal', $this->idPersona('222'));
        $c->set('asistenteTelefono', '3001112233')->set('controlNumeroInput', '1')->call('confirmControl')->call('saveAsistente');
        $r = $this->retiro($this->nominal);
        $r->set('retiroNumero', '1')->call('retirar');
        $pasos = ['retirar 1' => $this->estadoRetiro($r) + ['quorum' => $this->quorum($this->nominal)]];
        $r->call('closeModal')->set('reingresoNumero', '1')->call('reingresar');
        $pasos['reingresar 1'] = $this->estadoRetiro($r) + ['quorum' => $this->quorum($this->nominal)];

        return $this->resultado($pasos);
    }

    // ── Apoyo ───────────────────────────────────────────────────────

    /** 101 con poder 102, control 1, guardado. Devuelve los estados intermedios. */
    private function registrarConPoder($c): array
    {
        $c->call('requestSelectInmueble', $this->id('101'));
        $pasos = ['seleccionar 101' => $this->estado($c)];
        $c->set('poderSearch', '102');
        $pasos['buscar poder'] = $this->sinIds($c->get('poderResults'));
        $c->call('addPoder', $this->id('102'));
        $pasos['anexar 102'] = $this->estado($c);
        $c->set('asistenteTelefono', '3001112233')->set('asistenteNombre', 'Ana Asistente')->set('controlNumeroInput', '1')->call('confirmControl');
        $pasos['preparar control 1'] = $this->estado($c);
        $c->call('saveAsistente');
        $pasos['guardar'] = $this->estado($c);

        return $pasos;
    }

    /** Resultados de búsqueda sin el id interno (en MySQL depende del orden de las pruebas). */
    private function sinIds(array $filas): array
    {
        return array_map(fn ($f) => array_diff_key($f, ['id' => true]), $filas);
    }

    private function checkin(int $evento)
    {
        session(['active_event_id' => $evento]);

        return Livewire::test(RegistroPantalla::class);
    }

    private function retiro(int $evento)
    {
        session(['active_event_id' => $evento]);

        return Livewire::test(RetiroReingreso::class);
    }

    private function quorum(int $evento): array
    {
        session(['active_event_id' => $evento]);
        $q = Livewire::test(Quorum::class);

        return ['actual' => $q->get('quorumActual'), 'retirado' => $q->get('quorumRetirado'), 'personas' => $q->get('personasCheckin'),
            'personasRetiradas' => $q->get('personasRetiradas'), 'controlesActivos' => $q->get('controlesActivos'), 'controlesRetirados' => $q->get('controlesRetirados')];
    }

    private function id(string $inmueble): int
    {
        return (int) DB::table('evento_padron')->where('evento_id', $this->coef)->where('inmueble', $inmueble)->value('id');
    }

    private function idPersona(string $cedula): int
    {
        return (int) DB::table('evento_personas')->where('evento_id', $this->nominal)->where('cedula', $cedula)->value('id');
    }

    private function miembro(string $inmueble): int
    {
        return (int) DB::table('representacion_miembros')->where('evento_id', $this->coef)->where('padron_id', $this->id($inmueble))->value('id');
    }

    private function miembroNominal(string $cedula): int
    {
        return (int) DB::table('representacion_miembros_nominal')->where('evento_id', $this->nominal)->where('persona_id', $this->idPersona($cedula))->value('id');
    }

    private function evento(string $titulo, string $tipo, array $base): int
    {
        $slug = $tipo === 'nominal' ? 'n' : 'c';
        $ruta = "{$slug}/" . ($tipo === 'nominal' ? 'personas' : 'base') . '.xlsx';
        $this->xlsx($ruta, $base);
        $this->xlsx("{$slug}/controles.xlsx", [['Numero_control', 'Codigo_control'], [1, "{$slug}-C1"], [2, "{$slug}-C2"], [3, "{$slug}-C3"], [4, "{$slug}-C4"], [5, "{$slug}-C5"]]);
        $e = DB::table('eventos')->insertGetId([
            'titulo' => $titulo, 'slug' => "{$slug}-" . uniqid(), 'fecha_inicio' => '2026-10-15', 'activo' => 1, 'tipo_quorum' => $tipo, 'quorum_max' => 0,
            ($tipo === 'nominal' ? 'personas_excel_path' : 'base_excel_path') => $ruta, 'controles_excel_path' => "{$slug}/controles.xlsx",
            'created_at' => now(), 'updated_at' => now(),
        ]);
        Artisan::call($tipo === 'nominal' ? 'base:import-nominal' : 'base:import', ['evento_id' => $e, '--wipe' => true]);
        Artisan::call('controls:import-excel', ['evento_id' => $e, '--wipe' => true]);

        return $e;
    }

    private function xlsx(string $relativa, array $filas): void
    {
        $libro = new Spreadsheet();
        $libro->getActiveSheet()->fromArray($filas, null, 'A1', true);
        File::ensureDirectoryExists(dirname("{$this->disco}/{$relativa}"));
        (new Xlsx($libro))->save("{$this->disco}/{$relativa}");
    }

    /** Estado visible del check-in (sin ids internos). */
    private function estado($c): array
    {
        $p = fn ($k) => $c->get($k);

        return [
            'inmueble' => $p('inmuebleLabel'), 'propietario' => $p('propietarioLabel'), 'coefInmueble' => $p('coefInmueble'), 'estado' => $p('estado'),
            'cabeza' => $p('isCabezaSeleccionada'), 'coefTotal' => $p('coefTotal'), 'poderes' => $p('poderCount'),
            'miembros' => array_map(fn ($m) => [$m['inmueble'], $m['propietario'], $m['es_cabeza'], $m['coeficiente']], $p('miembros') ?? []),
            'asistente' => [$p('asistenteNombre'), $p('asistenteTelefono'), $p('asistenteCorreo')],
            'control' => [$p('controlNumero'), $p('controlSerial'), $p('controlPreviewSerial'), $p('controlPreviewEstado'), $p('controlPendienteNumero')],
            'mensajes' => array_filter([
                'checkin' => $p('checkinMsg'), 'checkinError' => $p('checkinError'), 'poder' => $p('poderMsg'), 'poderError' => $p('poderError'),
                'control' => $p('controlMsg'), 'controlError' => $p('controlError'), 'telefono' => $p('errorTelefono'),
                'modal' => $p('showControlModal') ? [$p('controlModalTitle'), $p('controlModalBody')] : null,
            ]),
            'seleccionado' => $p('registroId') !== null,
        ];
    }

    private function estadoRetiro($r): array
    {
        $p = fn ($k) => $r->get($k);

        return [
            'modal' => $p('modalOpen') ? [$p('modalType'), $p('modalTitle'), $p('modalBody')] : null,
            'reemplazo' => [$p('reemplazoInfoNombre'), $p('reemplazoInfoTelefono'), $p('reemplazoInfoInmueble'), $p('reemplazoInfoCedula'),
                $p('reemplazoInfoEstadoRegistro'), $p('reemplazoInfoEstadoControl'), $p('reemplazoNuevoEstadoControl'), $p('reemplazoNuevoSerial'), $p('reemplazoNuevoOk')],
            'consulta' => $p('consultaReady') ? [$p('consultaInmueble'), $p('consultaPropietario'), $p('consultaCedula'), $p('consultaAsistente'), $p('consultaTelefono'), $p('consultaEstadoRegistro')] : null,
        ];
    }

    private function resultado(array $pasos, bool $quorum = false): array
    {
        return ['pasos' => $pasos, 'foto' => $this->foto()]
            + ($quorum ? ['quorum' => ['coeficiente' => $this->quorum($this->coef), 'nominal' => $this->quorum($this->nominal)]] : []);
    }

    /** Foto normalizada de las tablas operativas: ids sustituidos por claves naturales, en orden de id. */
    private function foto(): array
    {
        $ev = DB::table('eventos')->pluck('titulo', 'id');
        $inm = DB::table('evento_padron')->pluck('inmueble', 'id');
        $ced = DB::table('evento_personas')->pluck('cedula', 'id');
        $num = DB::table('controles')->pluck('numero', 'id');
        $usr = DB::table('users')->pluck('email', 'id');
        $g = DB::table('representacion_grupos')->pluck('cabeza_padron_id', 'id')->map(fn ($p) => $inm[$p] ?? "#{$p}");
        $gn = DB::table('representacion_grupos_nominal')->pluck('cabeza_persona_id', 'id')->map(fn ($p) => $ced[$p] ?? "#{$p}");
        $reg = DB::table('registros_checkin')->get()->keyBy('id')->map(fn ($r) => $r->persona_id ? 'P' . ($ced[$r->persona_id] ?? '?') : 'I' . ($inm[$r->inmueble_base_id] ?? '?'));
        $t = fn (string $tabla, callable $fn) => DB::table($tabla)->orderBy('id')->get()->map($fn)->all();

        return [
            'grupos' => $t('representacion_grupos', fn ($r) => [$ev[$r->evento_id], $inm[$r->cabeza_padron_id] ?? "#{$r->cabeza_padron_id}", $r->control_numero, $r->checkin_at]),
            'miembros' => $t('representacion_miembros', fn ($r) => [$ev[$r->evento_id], $g[$r->grupo_id] ?? "#{$r->grupo_id}", $inm[$r->padron_id] ?? "#{$r->padron_id}", (int) $r->es_cabeza]),
            'grupos_nominal' => $t('representacion_grupos_nominal', fn ($r) => [$ev[$r->evento_id], $ced[$r->cabeza_persona_id] ?? "#{$r->cabeza_persona_id}", $r->control_numero, $r->checkin_at]),
            'miembros_nominal' => $t('representacion_miembros_nominal', fn ($r) => [$ev[$r->evento_id], $gn[$r->grupo_id] ?? "#{$r->grupo_id}", $ced[$r->persona_id] ?? "#{$r->persona_id}", (int) $r->es_cabeza]),
            'controles' => $t('controles', fn ($r) => [$ev[$r->evento_id], (int) $r->numero, $r->serial, $r->estado, $r->asignado_a_registro_id ? ($reg[$r->asignado_a_registro_id] ?? '#borrado') : null]),
            'registros' => $t('registros_checkin', fn ($r) => [
                'evento' => $ev[$r->evento_id], 'quien' => $reg[$r->id],
                'grupo' => $r->grupo_id === null ? null : ($r->persona_id ? ($gn[$r->grupo_id] ?? "#{$r->grupo_id}") : ($g[$r->grupo_id] ?? "#{$r->grupo_id}")),
                'estado' => $r->estado, 'asistente' => [$r->asistente_nombre, $r->asistente_telefono, $r->asistente_correo],
                'control' => $r->control_id ? ($num[$r->control_id] ?? '#borrado') : null, 'control_numero_snapshot' => $r->control_numero_snapshot, 'control_serial_snapshot' => $r->control_serial_snapshot,
                'coef_total_snapshot' => $r->coef_total_snapshot === null ? null : (float) $r->coef_total_snapshot, 'cabeza_snapshot' => $r->cabeza_inmueble_snapshot,
                'checked_in' => [$r->checked_in_at, $r->checked_in_by_user_id ? $usr[$r->checked_in_by_user_id] : null],
                'retirado' => [$r->retirado_at, $r->retirado_by_user_id ? $usr[$r->retirado_by_user_id] : null],
                'reingreso' => [$r->reingreso_at, $r->reingreso_by_user_id ? $usr[$r->reingreso_by_user_id] : null],
            ]),
        ];
    }
}
