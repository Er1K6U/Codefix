<?php

namespace Tests\Feature;

use App\Livewire\Checkin\RegistroPantalla;
use App\Livewire\Controls\RetiroReingreso;
use App\Livewire\Quorum\Show as Quorum;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use PDOException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Seguridad e integridad del check-in (COEFIX 2.0, Etapa 1I): riesgos I1–I8 y decisiones D1–D3.
 * Las peticiones "forjadas" se simulan con Testable::update(), que envía el cambio de propiedad y la
 * acción en la misma petición, como lo haría un navegador manipulado.
 */
class CheckinSeguridadTest extends TestCase
{
    use RefreshDatabase;

    private string $disco;
    private int $a;
    private int $b;
    private int $nominal;
    private User $operador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        $this->disco = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'coefix_seguridad_' . uniqid();
        File::ensureDirectoryExists($this->disco);
        config(['filesystems.disks.local.root' => $this->disco, 'filesystems.disks.private.root' => $this->disco, 'excel.temporary_files.local_path' => $this->disco]);

        foreach (['ADMIN', 'OPERADOR', 'CLIENTE'] as $rol) {
            Role::create(['name' => $rol]);
        }
        $this->operador = User::factory()->create();
        $this->operador->assignRole('OPERADOR');
        $this->actingAs($this->operador);

        $this->a = $this->evento('A', 'coeficiente', 'A');
        $this->b = $this->evento('B', 'coeficiente', 'B');
        $this->nominal = $this->evento('N', 'nominal', 'N');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->disco);
        parent::tearDown();
    }

    // ── I1–I8 ──────────────────────────────────────────────────────

    public function test_i1_inmueble_obsoleto_tras_reimportacion_no_deja_huerfanos(): void
    {
        $c = $this->checkin($this->a)->set('search', 'A-101');
        $idViejo = $c->get('results')[0]['id'];
        $this->assertSame(0, Artisan::call('base:import', ['evento_id' => $this->a, '--wipe' => true]), 'reimportación permitida (sin actividad)');

        $c->call('selectInmueble', $idViejo);

        $this->assertStringContainsString('ya no está en esta asamblea', $c->get('checkinError'));
        $this->assertNull($c->get('registroId'));
        $this->assertSame(0, DB::table('registros_checkin')->count(), 'no se creó ningún registro');
        $this->assertSinHuerfanos();

        // Con la base nueva sí se puede registrar.
        $c->set('search', 'A-101')->call('selectInmueble', $c->get('results')[0]['id']);
        $this->assertSame('A-101', $c->get('inmuebleLabel'));
        $this->assertSame(1, DB::table('registros_checkin')->count());
    }

    public function test_i2_persona_obsoleta_tras_reimportacion(): void
    {
        $c = $this->checkin($this->nominal)->set('search', 'N111');
        $idViejo = $c->get('results')[0]['id'];
        Artisan::call('base:import-nominal', ['evento_id' => $this->nominal, '--wipe' => true]);

        $c->call('selectPersona', $idViejo);

        $this->assertStringContainsString('ya no está en esta asamblea', $c->get('checkinError'));
        $this->assertSame(0, DB::table('registros_checkin')->count());
    }

    public function test_i3_inmueble_de_otro_evento_se_rechaza(): void
    {
        $fotoB = $this->foto($this->b);
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->b, 'B-101'));

        $this->assertStringContainsString('ya no está en esta asamblea', $c->get('checkinError'));
        $this->assertSame(0, DB::table('registros_checkin')->count());
        $this->assertSame(0.0, (float) $this->quorum($this->a));
        $this->assertSame($fotoB, $this->foto($this->b));
    }

    public function test_i4_el_navegador_no_puede_cambiar_el_evento(): void
    {
        $fotoB = $this->foto($this->b);
        $c = $this->checkin($this->a);

        $this->assertForjadaRechazada(fn () => $c->update(
            calls: [['method' => 'selectInmueble', 'params' => [$this->id($this->b, 'B-101')], 'path' => '']],
            updates: ['eventoId' => $this->b],
        ));

        $this->assertSame(0, DB::table('registros_checkin')->count());
        $this->assertSame($fotoB, $this->foto($this->b));
    }

    public function test_i5_el_navegador_no_puede_cambiar_el_coeficiente(): void
    {
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-103'));
        $c->set('asistenteTelefono', '3001112233')->set('controlNumeroInput', '1')->call('confirmControl');

        $this->assertForjadaRechazada(fn () => $c->update(calls: [['method' => 'saveAsistente', 'params' => [], 'path' => '']], updates: ['coefTotal' => 99.0]));
        $this->assertSame(0, DB::table('registros_checkin')->where('estado', 'CHECKED_IN')->count());

        $c->call('saveAsistente');
        $this->assertSame(20.0, (float) DB::table('registros_checkin')->value('coef_total_snapshot'));
        $this->assertSame(20.0, (float) $this->quorum($this->a));
    }

    public function test_i5_el_coeficiente_se_recalcula_en_la_base_al_guardar(): void
    {
        // Dos puestos abren la cabeza 101; el segundo anexa un poder; el primero guarda con su pantalla
        // desactualizada (30): el snapshot sale de la base (30 + 35 = 65).
        $p1 = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101'));
        $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101'))->call('addPoder', $this->id($this->a, 'A-102'));
        $this->assertSame(40.0, $p1->get('coefTotal'));

        $p1->set('asistenteTelefono', '3001112233')->set('controlNumeroInput', '1')->call('confirmControl')->call('saveAsistente');

        $this->assertSame(75.0, (float) DB::table('registros_checkin')->value('coef_total_snapshot'));
        $this->assertSame(75.0, (float) $this->quorum($this->a));
    }

    public function test_i6_poder_de_otro_evento_se_rechaza(): void
    {
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-103'));
        $c->call('addPoder', $this->id($this->b, 'B-101'));

        $this->assertStringContainsString('no está en esta asamblea', $c->get('poderError'));
        $this->assertSame(20.0, $c->get('coefTotal'));
        $this->assertSame(0, DB::table('representacion_miembros')->where('evento_id', $this->a)->where('es_cabeza', 0)->count());
    }

    public function test_i6_poder_nominal_de_otro_evento_se_rechaza(): void
    {
        $otro = $this->evento('N2', 'nominal', 'M');
        $c = $this->checkin($this->nominal)->call('selectPersona', $this->persona($this->nominal, 'N111'));
        $c->call('addPoderNominal', $this->persona($otro, 'M222'));

        $this->assertStringContainsString('no está en esta asamblea', $c->get('poderError'));
        $this->assertSame(0, DB::table('representacion_miembros_nominal')->where('es_cabeza', 0)->count());
    }

    public function test_i7_el_navegador_no_puede_cambiar_el_grupo(): void
    {
        $grupo101 = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101'))->get('grupoId');
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-103'));

        $this->assertForjadaRechazada(fn () => $c->update(
            calls: [['method' => 'addPoder', 'params' => [$this->id($this->a, 'A-102')], 'path' => '']], updates: ['grupoId' => $grupo101]));

        $this->assertSame(1, DB::table('representacion_miembros')->where('grupo_id', $grupo101)->count(), 'el grupo de 101 no cambió');
    }

    #[DataProvider('propiedadesBloqueadas')]
    public function test_propiedades_del_servidor_no_se_pueden_cambiar(string $componente, string $propiedad, mixed $valor): void
    {
        $c = $componente === 'checkin' ? $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101')) : $this->retiro($this->a);

        $this->expectException(CannotUpdateLockedPropertyException::class);
        $c->set($propiedad, $valor);
    }

    public static function propiedadesBloqueadas(): array
    {
        $casos = [];
        foreach (['eventoId' => 999, 'tipoQuorum' => 'nominal', 'registroId' => 999, 'inmuebleBaseId' => 999, 'personaId' => 999, 'grupoId' => 999,
            'isCabezaSeleccionada' => true, 'coefTotal' => 99.0, 'miembros' => [], 'controlPendienteId' => 999, 'controlPendienteNumero' => 9,
            'pendingInmuebleId' => 999, 'inmuebleLabel' => 'X'] as $p => $v) {
            $casos["checkin {$p}"] = ['checkin', $p, $v];
        }
        foreach (['eventoId' => 999, 'tipoQuorum' => 'nominal', 'reemplazoRegistroId' => 999, 'reemplazoControlId' => 999, 'reemplazoNuevoOk' => true] as $p => $v) {
            $casos["retiro {$p}"] = ['retiro', $p, $v];
        }

        return $casos;
    }

    public function test_i8_d2_control_preparado_no_pasa_a_otra_persona(): void
    {
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101'))
            ->set('controlNumeroInput', '1')->call('confirmControl');
        $this->assertSame(1, $c->get('controlPendienteNumero'));

        $c->call('clearSelection')->call('selectInmueble', $this->id($this->a, 'A-103'))->set('asistenteTelefono', '3001112233');
        $this->assertNull($c->get('controlPendienteNumero'), 'D2: el control preparado se limpia al cambiar de selección');

        $c->call('saveAsistente');
        $c->assertHasErrors('control');
        $this->assertSame(0, DB::table('registros_checkin')->where('estado', 'CHECKED_IN')->count());
        $this->assertSame('LIBRE', $this->control($this->a, 1)->estado);
    }

    public function test_d2_no_toca_controles_ya_asignados(): void
    {
        $this->registrar($this->a, 'A-101', 1);
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101'));
        $this->assertSame(1, $c->get('controlNumero'));
        $c->call('clearSelection')->call('selectInmueble', $this->id($this->a, 'A-103'));

        $this->assertSame('ASIGNADO', $this->control($this->a, 1)->estado);
        $this->assertSame(1, (int) DB::table('registros_checkin as r')->join('controles as c', 'c.id', '=', 'r.control_id')->where('r.inmueble_base_id', $this->id($this->a, 'A-101'))->value('c.numero'));
    }

    // ── D1: acceso ─────────────────────────────────────────────────

    public function test_d1_cliente_no_entra_a_check_in_ni_retiros(): void
    {
        $cliente = User::factory()->create();
        $cliente->assignRole('CLIENTE');
        $this->actingAs($cliente);
        session(['active_event_id' => $this->a]);

        $this->get('/checkin')->assertForbidden();
        $this->get('/controles/retiro')->assertForbidden();
        Livewire::test(RegistroPantalla::class)->assertForbidden();
        Livewire::test(RetiroReingreso::class)->assertForbidden();
        $this->get('/quorum')->assertOk();
    }

    public function test_d1_permiso_se_comprueba_en_cada_accion(): void
    {
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101'));
        $r = $this->retiro($this->a);
        $this->operador->syncRoles(['CLIENTE']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $c->call('addPoder', $this->id($this->a, 'A-102'))->assertForbidden();
        $r->call('retirar')->assertForbidden();
        $this->assertSame(0, DB::table('representacion_miembros')->where('es_cabeza', 0)->count());
    }

    public function test_d1_usuario_desactivado_no_sigue_operando(): void
    {
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101'));
        DB::table('users')->where('id', $this->operador->id)->update(['activo' => 0]);
        $this->operador->refresh();

        $c->call('addPoder', $this->id($this->a, 'A-102'))->assertForbidden();
        $this->assertSame(0, DB::table('representacion_miembros')->where('es_cabeza', 0)->count());
    }

    public function test_d1_admin_y_operador_operan(): void
    {
        foreach (['ADMIN', 'OPERADOR'] as $rol) {
            $u = User::factory()->create();
            $u->assignRole($rol);
            $this->actingAs($u);
            session(['active_event_id' => $this->a]);
            $this->get('/checkin')->assertOk();
            $this->get('/controles/retiro')->assertOk();
        }
        $this->registrar($this->a, 'A-101', 1);
        $this->retiro($this->a)->set('retiroNumero', '1')->call('retirar')->assertSet('modalTitle', 'Control retirado');
    }

    // ── D3: atomicidad de poderes ──────────────────────────────────

    public function test_d3_quitar_poder_que_falla_no_libera_su_control(): void
    {
        // 102 es poder de 101 y tiene control 2; además 102 sigue siendo cabeza de su grupo base con
        // otros poderes (103 y 104): quitarlo debe fallar sin liberar el control.
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101'))->call('addPoder', $this->id($this->a, 'A-102'));
        $this->conflictoBase102($this->a);
        $antes = $this->foto($this->a);

        $c->call('removePoder', $this->miembro($this->a, 'A-102'));

        $this->assertStringContainsString('No se pudo separar este poder', $c->get('poderError'));
        $this->assertSame($antes, $this->foto($this->a), 'la transacción revirtió todo (el control 2 sigue asignado)');
        $this->assertSame('ASIGNADO', $this->control($this->a, 2)->estado);
    }

    public function test_d3_quitar_poder_nominal_que_falla_revierte(): void
    {
        $e = $this->nominal;
        $c = $this->checkin($e)->call('selectPersona', $this->persona($e, 'N111'))->call('addPoderNominal', $this->persona($e, 'N222'));
        // 222 tiene control 2 y además sigue siendo cabeza de su grupo base con poderes (333 y 444).
        $base222 = DB::table('representacion_grupos_nominal')->where('evento_id', $e)->where('cabeza_persona_id', $this->persona($e, 'N222'))->value('id');
        DB::table('representacion_miembros_nominal')->whereIn('persona_id', [$this->persona($e, 'N333'), $this->persona($e, 'N444')])->update(['grupo_id' => $base222, 'es_cabeza' => 0]);
        $r = DB::table('registros_checkin')->insertGetId(['evento_id' => $e, 'persona_id' => $this->persona($e, 'N222'), 'estado' => 'EN_PROCESO',
            'control_id' => $this->control($e, 2)->id, 'control_numero_snapshot' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('controles')->where('id', $this->control($e, 2)->id)->update(['estado' => 'ASIGNADO', 'asignado_a_registro_id' => $r]);
        $antes = $this->foto($e);

        $c->call('removePoderNominal', $this->miembroNominal($e, 'N222'));

        $this->assertStringContainsString('No se pudo separar', $c->get('poderError'));
        $this->assertSame($antes, $this->foto($e));
    }

    public function test_d3_separar_cabeza_que_falla_revierte(): void
    {
        // 101 (control 1) representa a 102, que ya tiene control 2: separar debe fallar sin cambiar nada.
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101'))->call('addPoder', $this->id($this->a, 'A-102'));
        $c->set('asistenteTelefono', '3001112233')->set('controlNumeroInput', '1')->call('confirmControl')->call('saveAsistente');
        $r = DB::table('registros_checkin')->insertGetId(['evento_id' => $this->a, 'grupo_id' => null, 'inmueble_base_id' => $this->id($this->a, 'A-102'),
            'estado' => 'EN_PROCESO', 'control_id' => $this->control($this->a, 2)->id, 'control_numero_snapshot' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('controles')->where('id', $this->control($this->a, 2)->id)->update(['estado' => 'ASIGNADO', 'asignado_a_registro_id' => $r]);
        $c->call('selectInmueble', $this->id($this->a, 'A-101'));
        $antes = $this->foto($this->a);

        $c->call('separarCabeza');

        $this->assertStringContainsString('el nuevo cabeza ya tiene un control asignado', $c->get('checkinError'));
        $this->assertSame($antes, $this->foto($this->a), 'sin cambio de cabeza ni grupos a medias');
    }

    // ── Contexto, concurrencia y aislamiento ───────────────────────

    public function test_cambio_de_evento_en_el_puesto_reinicia_la_seleccion(): void
    {
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101'));
        session(['active_event_id' => $this->b]);

        $c->call('addPoder', $this->id($this->b, 'B-102'));

        $this->assertNull($c->get('registroId'));
        $this->assertSame($this->b, $c->get('eventoId'));
        $this->assertSame('Primero selecciona un inmueble.', $c->get('poderError'));
        $this->assertSame(0, DB::table('representacion_miembros')->where('es_cabeza', 0)->count());
    }

    public function test_conflicto_de_concurrencia_revierte_y_avisa(): void
    {
        $c = $this->checkin($this->a)->call('selectInmueble', $this->id($this->a, 'A-101'))->call('addPoder', $this->id($this->a, 'A-102'));
        $antes = $this->foto($this->a);
        // Simula que MySQL elige esta transacción como víctima de un interbloqueo (40001 / 1213).
        DB::connection()->beforeExecuting(function (string $sql) {
            if (str_starts_with($sql, 'update') && str_contains($sql, 'representacion_miembros')) {
                $pdo = new PDOException('Deadlock found when trying to get lock; try restarting transaction');
                $pdo->errorInfo = ['40001', 1213, 'Deadlock found when trying to get lock; try restarting transaction'];
                throw new QueryException(DB::getName(), $sql, [], $pdo);
            }
        });

        $c->call('removePoder', $this->miembro($this->a, 'A-102'));

        $this->assertStringContainsString('Otra estación estaba usando estos mismos datos', $c->get('poderError'));
        $this->assertSame($antes, $this->foto($this->a));
    }

    public function test_flujo_completo_no_toca_otros_eventos_ni_deja_huerfanos(): void
    {
        $fotoB = $this->foto($this->b);
        $this->registrar($this->a, 'A-101', 1, ['A-102']);
        $this->registrar($this->a, 'A-103', 2);
        $r = $this->retiro($this->a);
        $r->set('retiroNumero', '2')->call('retirar')->call('closeModal')->set('reingresoNumero', '2')->call('reingresar');
        $r->call('closeModal')->set('reemplazoNumeroActual', '1')->call('buscarControlActual')->set('reemplazoNumeroNuevo', '3')->call('buscarControlNuevo')->call('reemplazarControl');

        $this->assertSame('Control reemplazado', $r->get('modalTitle'));
        $this->assertSame(95.0, (float) $this->quorum($this->a), '40 + 35 + 20');
        $this->assertSame($fotoB, $this->foto($this->b));
        $this->assertSinHuerfanos();
    }

    // ── Apoyo ───────────────────────────────────────────────────────

    private function assertForjadaRechazada(callable $peticion): void
    {
        try {
            $peticion();
            $this->fail('La petición forjada debía rechazarse.');
        } catch (CannotUpdateLockedPropertyException) {
            $this->addToAssertionCount(1);
        }
    }

    /** 102 (poder de 101) con control 2 y cabeza de su grupo base con otros poderes (103 y 104). */
    private function conflictoBase102(int $e): void
    {
        $p102 = $this->id($e, 'A-102');
        $base = DB::table('representacion_grupos')->where('evento_id', $e)->where('cabeza_padron_id', $p102)->value('id');
        DB::table('representacion_miembros')->whereIn('padron_id', [$this->id($e, 'A-103'), $this->id($e, 'A-104')])->update(['grupo_id' => $base, 'es_cabeza' => 0]);
        $r = DB::table('registros_checkin')->insertGetId(['evento_id' => $e, 'grupo_id' => null, 'inmueble_base_id' => $p102, 'estado' => 'EN_PROCESO',
            'control_id' => $this->control($e, 2)->id, 'control_numero_snapshot' => 2, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('controles')->where('id', $this->control($e, 2)->id)->update(['estado' => 'ASIGNADO', 'asignado_a_registro_id' => $r]);
    }

    private function registrar(int $e, string $inmueble, int $control, array $poderes = []): void
    {
        $c = $this->checkin($e)->call('selectInmueble', $this->id($e, $inmueble));
        foreach ($poderes as $p) {
            $c->call('addPoder', $this->id($e, $p));
        }
        $c->set('asistenteTelefono', '3001112233')->set('controlNumeroInput', (string) $control)->call('confirmControl')->call('saveAsistente');
        $this->assertSame('✅ Check-in cerrado correctamente.', $c->get('checkinMsg'));
    }

    private function assertSinHuerfanos(): void
    {
        $huerfanos = DB::table('registros_checkin as r')
            ->leftJoin('evento_padron as p', fn ($j) => $j->on('p.id', '=', 'r.inmueble_base_id')->on('p.evento_id', '=', 'r.evento_id'))
            ->leftJoin('evento_personas as pe', fn ($j) => $j->on('pe.id', '=', 'r.persona_id')->on('pe.evento_id', '=', 'r.evento_id'))
            ->whereNull('p.id')->whereNull('pe.id')->count();
        $this->assertSame(0, $huerfanos, 'registros sin inmueble/persona válidos de su evento');
        $cruzados = DB::table('representacion_miembros as m')->join('evento_padron as p', 'p.id', '=', 'm.padron_id')->whereColumn('p.evento_id', '!=', 'm.evento_id')->count();
        $this->assertSame(0, $cruzados, 'miembros con inmuebles de otro evento');
    }

    private function checkin(int $e)
    {
        session(['active_event_id' => $e]);

        return Livewire::test(RegistroPantalla::class);
    }

    private function retiro(int $e)
    {
        session(['active_event_id' => $e]);

        return Livewire::test(RetiroReingreso::class);
    }

    private function quorum(int $e): float
    {
        session(['active_event_id' => $e]);

        return (float) Livewire::test(Quorum::class)->get('quorumActual');
    }

    private function id(int $e, string $inm): int
    {
        return (int) DB::table('evento_padron')->where('evento_id', $e)->where('inmueble', $inm)->value('id');
    }

    private function persona(int $e, string $ced): int
    {
        return (int) DB::table('evento_personas')->where('evento_id', $e)->where('cedula', $ced)->value('id');
    }

    private function miembro(int $e, string $inm): int
    {
        return (int) DB::table('representacion_miembros')->where('evento_id', $e)->where('padron_id', $this->id($e, $inm))->value('id');
    }

    private function miembroNominal(int $e, string $ced): int
    {
        return (int) DB::table('representacion_miembros_nominal')->where('evento_id', $e)->where('persona_id', $this->persona($e, $ced))->value('id');
    }

    private function control(int $e, int $numero): object
    {
        return DB::table('controles')->where('evento_id', $e)->where('numero', $numero)->first();
    }

    private function evento(string $titulo, string $tipo, string $pref): int
    {
        $base = $tipo === 'nominal'
            ? [['Cedula', 'Nombre'], ["{$pref}111", 'Ana'], ["{$pref}222", 'Bruno'], ["{$pref}333", 'Carla'], ["{$pref}444", 'Diana']]
            : [['Inmueble', 'Propietario', 'Coeficiente'], ["{$pref}-101", 'Ana', 40], ["{$pref}-102", 'Bruno', 35], ["{$pref}-103", 'Carla', 20], ["{$pref}-104", 'Diego', 5]];
        $this->xlsx("{$pref}/base.xlsx", $base);
        $this->xlsx("{$pref}/controles.xlsx", [['Numero_control', 'Codigo_control'], [1, "{$pref}-C1"], [2, "{$pref}-C2"], [3, "{$pref}-C3"]]);
        $e = DB::table('eventos')->insertGetId(['titulo' => $titulo, 'slug' => $pref . uniqid(), 'fecha_inicio' => '2026-10-15', 'activo' => 1, 'tipo_quorum' => $tipo, 'quorum_max' => 0,
            ($tipo === 'nominal' ? 'personas_excel_path' : 'base_excel_path') => "{$pref}/base.xlsx", 'controles_excel_path' => "{$pref}/controles.xlsx",
            'created_at' => now(), 'updated_at' => now()]);
        Artisan::call($tipo === 'nominal' ? 'base:import-nominal' : 'base:import', ['evento_id' => $e, '--wipe' => true]);
        Artisan::call('controls:import-excel', ['evento_id' => $e, '--wipe' => true]);

        return $e;
    }

    private function xlsx(string $rel, array $filas): void
    {
        $l = new Spreadsheet();
        $l->getActiveSheet()->fromArray($filas, null, 'A1', true);
        File::ensureDirectoryExists(dirname("{$this->disco}/{$rel}"));
        (new Xlsx($l))->save("{$this->disco}/{$rel}");
    }

    /** Todas las filas operativas de un evento (para comprobar que nada cambió). */
    private function foto(int $e): array
    {
        $foto = [];
        foreach (['evento_padron', 'evento_personas', 'representacion_grupos', 'representacion_miembros', 'representacion_grupos_nominal',
            'representacion_miembros_nominal', 'controles', 'registros_checkin'] as $t) {
            $foto[$t] = DB::table($t)->where('evento_id', $e)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        }

        return $foto;
    }
}
