<?php

namespace Tests\Feature;

use App\Livewire\Checkin\RegistroPantalla;
use App\Livewire\Controls\RetiroReingreso;
use App\Livewire\Event\Index as EventosIndex;
use App\Livewire\Quorum\Show as Quorum;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Navegación y acceso por rol (COEFIX 2.0, Etapa 1I): ADMIN y OPERADOR operan el check-in;
 * CLIENTE no lo ve en el menú, al activar su puesto va al quórum y el servidor le niega el
 * check-in aunque llegue por URL directa o por una petición interna de Livewire.
 */
class NavegacionRolesTest extends TestCase
{
    use RefreshDatabase;

    private int $evento;
    private int $otro;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ADMIN', 'OPERADOR', 'CLIENTE'] as $rol) {
            Role::create(['name' => $rol]);
        }
        $this->evento = $this->evento('Asamblea');
        $this->otro = $this->evento('Otra asamblea');
    }

    public static function roles(): array
    {
        return [
            'ADMIN' => ['ADMIN', true],
            'OPERADOR' => ['OPERADOR', true],
            'CLIENTE' => ['CLIENTE', false],
        ];
    }

    #[DataProvider('roles')]
    public function test_menu_muestra_check_in_solo_a_quien_lo_opera(string $rol, bool $opera): void
    {
        $this->como($rol);

        $html = $this->get('/eventos')->assertOk()->getContent();

        $this->assertSame($opera, str_contains($html, 'href="' . route('checkin') . '"'), 'enlace Check-in');
        $this->assertStringContainsString('href="' . url('/quorum') . '"', $html, 'todos conservan Quórum');
        $this->assertSame($opera, str_contains($html, 'href="' . url('/controles/retiro') . '"'), 'enlace Retiro/Controles');
    }

    #[DataProvider('roles')]
    public function test_rutas_directas_respetan_el_permiso(string $rol, bool $opera): void
    {
        $this->como($rol);
        session(['active_event_id' => $this->evento]);

        $this->get('/checkin')->assertStatus($opera ? 200 : 403);
        $this->get('/controles/retiro')->assertStatus($opera ? 200 : 403);
        $this->get('/quorum')->assertOk();
        $this->get('/eventos')->assertOk();
        $this->get('/base-turning')->assertStatus($rol === 'ADMIN' ? 200 : 403);
    }

    #[DataProvider('roles')]
    public function test_peticiones_internas_de_livewire_respetan_el_permiso(string $rol, bool $opera): void
    {
        $this->como($rol);
        session(['active_event_id' => $this->evento]);
        $padron = (int) DB::table('evento_padron')->where('evento_id', $this->evento)->value('id');

        if ($opera) {
            Livewire::test(RegistroPantalla::class)->call('selectInmueble', $padron)->assertSet('inmuebleLabel', '101');
            Livewire::test(RetiroReingreso::class)->set('consultaNumero', '1')->call('consultarControl')->assertSet('modalTitle', 'Sin historial');
        } else {
            Livewire::test(RegistroPantalla::class)->assertForbidden();
            Livewire::test(RetiroReingreso::class)->assertForbidden();
            $this->assertSame(0, DB::table('registros_checkin')->count(), 'nada se escribió');
        }

        // El quórum (y su sondeo periódico) sigue disponible para todos.
        Livewire::test(Quorum::class)->assertOk()->call('refreshData')->assertOk();
    }

    #[DataProvider('roles')]
    public function test_al_activar_el_puesto_va_a_su_pantalla(string $rol, bool $opera): void
    {
        $this->como($rol);
        $destino = route($opera ? 'checkin' : 'quorum.show');

        Livewire::test(EventosIndex::class)->call('activateForThisStation', $this->evento)->assertRedirect($destino);
        $this->assertSame($this->evento, session('active_event_id'));

        // Cambiar a otra asamblea (con confirmación) lleva al mismo destino.
        Livewire::test(EventosIndex::class)
            ->call('activateForThisStation', $this->otro)->assertSet('confirmChange', true)
            ->call('confirmChangeEvent')->assertRedirect($destino);
        $this->assertSame($this->otro, session('active_event_id'));
    }

    public function test_cliente_conserva_sus_demas_funciones(): void
    {
        $this->como('CLIENTE');
        session(['active_event_id' => $this->evento]);

        // Peticiones HTTP primero: tras Livewire::test, redirect() queda ligado al Redirector de
        // Livewire dentro de la misma prueba (en producción cada petición es independiente).
        $this->get('/eventos')->assertOk()->assertSee('Asamblea');
        $this->get('/quorum')->assertOk();
        // Sigue sin poder crear eventos ni ver Base Turning (sin cambios).
        $this->get('/eventos/crear')->assertForbidden();
        $this->get('/base-turning')->assertForbidden();

        // Activar y quitar su puesto.
        session()->forget('active_event_id');
        Livewire::test(EventosIndex::class)->call('activateForThisStation', $this->evento)->assertRedirect(route('quorum.show'));
        Livewire::test(EventosIndex::class)->call('clearActiveForThisStation')->assertSet('activeEventId', null);
    }

    private function como(string $rol): User
    {
        $u = User::factory()->create();
        $u->assignRole($rol);
        $this->actingAs($u);

        return $u;
    }

    private function evento(string $titulo): int
    {
        $e = DB::table('eventos')->insertGetId(['titulo' => $titulo, 'slug' => 'e-' . uniqid(), 'fecha_inicio' => '2026-10-15', 'activo' => 1,
            'tipo_quorum' => 'coeficiente', 'quorum_max' => 0, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('evento_padron')->insert(['evento_id' => $e, 'inmueble' => '101', 'propietario' => 'Ana', 'coeficiente' => 100]);
        DB::table('controles')->insert(['evento_id' => $e, 'numero' => 1, 'serial' => 'C-1', 'estado' => 'LIBRE']);

        return $e;
    }
}
