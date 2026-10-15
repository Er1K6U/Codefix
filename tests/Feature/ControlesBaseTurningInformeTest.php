<?php

namespace Tests\Feature;

use App\Exports\InformeAsambleaExport;
use App\Livewire\BaseTurning\Index as BaseTurning;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

/**
 * Regresión de Base Turning y del informe Excel (COEFIX 2.0, Etapa 1G).
 *
 * Los resultados de referencia (tests/Fixtures/controles/*.json) se generaron con el
 * código anterior a la optimización SQL. Para regenerarlos: COEFIX_ACTUALIZAR_REFERENCIA=1.
 *
 * Escenarios: control por id, por número snapshot, sin coincidencia, retirado, retirado y
 * reingresado, reemplazado, id y número que apuntan a controles distintos (fila duplicada,
 * comportamiento existente), números repetidos entre eventos, poderes, grupos y cabezas,
 * registros en proceso excluidos, inmuebles/personas ausentes, modo coeficiente y nominal.
 */
class ControlesBaseTurningInformeTest extends TestCase
{
    use RefreshDatabase;

    private const REFERENCIA = __DIR__ . '/../Fixtures/controles';

    private array $ev = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-15 10:00:00');
        config(['excel.temporary_files.local_path' => sys_get_temp_dir()]);
        $this->actingAs(User::factory()->create());
        $this->sembrarEscenarios();
    }

    public function test_base_turning_y_seis_hojas_coinciden_con_la_referencia(): void
    {
        // Una referencia por motor (sqlite en la suite normal; mysql si se ejecuta contra MySQL),
        // porque cada motor devuelve los decimales con distinto formato.
        $dir = self::REFERENCIA . '/' . DB::getDriverName();

        foreach ($this->ev as $nombre => $eid) {
            $actual = $this->salidas($eid);
            $archivo = "{$dir}/{$nombre}.json";

            if (getenv('COEFIX_ACTUALIZAR_REFERENCIA')) {
                @mkdir($dir, 0777, true);
                file_put_contents($archivo, json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n");
            }

            $this->assertFileExists($archivo);
            $this->assertSame(json_decode(file_get_contents($archivo), true), json_decode(json_encode($actual, JSON_PRESERVE_ZERO_FRACTION), true), "Salida distinta para el escenario {$nombre}");
        }
    }

    public function test_comportamientos_de_controles_documentados(): void
    {
        $bt = $this->baseTurning($this->ev['coeficiente_a']);
        $porNumero = fn ($n) => array_values(array_filter($bt['rows'], fn ($r) => (string) $r['control_numero'] === (string) $n));

        $this->assertSame('A-0001', $porNumero(1)[0]['codigo'], 'serial del snapshot');
        $this->assertSame('A-0002', $porNumero(2)[0]['codigo'], 'serial por número snapshot (sin control_id)');
        $this->assertSame('A-0003', $porNumero(3)[0]['codigo'], 'serial por control_id (retirado)');
        $this->assertSame('RETIRADO', $porNumero(3)[0]['estado']);
        $this->assertSame('A-0007', $porNumero(7)[0]['codigo'], 'control reemplazado');
        $this->assertSame('', $porNumero(99)[0]['codigo'], 'control sin coincidencia');
        // Comportamiento existente: formatCoef() multiplica por 100 (pensado para fracciones),
        // y los coeficientes de Coefix van en porcentaje, así que 20 + 15 = 35 se exporta como 3500.
        $this->assertSame('3500', $porNumero(1)[0]['coef'], 'cabeza con poder suma 20 + 15');

        // Comportamiento existente: si control_id y el número snapshot apuntan a controles
        // distintos, el registro aparece dos veces (una por cada control). Se conserva tal cual.
        $dup = $porNumero(6);
        $this->assertCount(2, $dup);
        $this->assertEqualsCanonicalizing(['A-0005', 'A-0006'], array_column($dup, 'codigo'));

        $inmuebles = array_column($bt['rows'], 'inmueble');
        $this->assertNotContains('T1-102', $inmuebles, 'registro EN_PROCESO excluido');
        $this->assertNotContains('T3-101', $inmuebles, 'inmueble ausente');

        $btB = $this->baseTurning($this->ev['coeficiente_b']);
        $this->assertSame(['B-0001', 'B-0002'], array_column($btB['rows'], 'codigo'), 'números repetidos entre eventos: solo controles del mismo evento');

        $btN = $this->baseTurning($this->ev['nominal_c']);
        $this->assertSame(['C-0001', 'C-0002', 'C-0003'], array_column(array_slice($btN['rows'], 0, 3), 'codigo'));
        $this->assertSame('2', $btN['rows'][0]['coef'], 'cabeza nominal con un poder = 2 votos');
        $this->assertEqualsCanonicalizing(['C-0004', 'C-0005'], array_column(array_filter($btN['rows'], fn ($r) => $r['propietario'] === 'Elena Ficticia'), 'codigo'));
    }

    public function test_el_excel_se_genera_con_las_seis_hojas(): void
    {
        foreach ($this->ev as $eid) {
            $tmp = tempnam(sys_get_temp_dir(), 'coefix') . '.xlsx';
            file_put_contents($tmp, Excel::raw(new InformeAsambleaExport($eid), ExcelFormat::XLSX));
            $this->assertSame(['Resumen', 'Asistencia', 'Quórum', 'Ausentes', 'Poderes', 'Poderes Detalle'], IOFactory::load($tmp)->getSheetNames());
            @unlink($tmp);
        }
    }

    // ---------------------------------------------------------------------

    private function salidas(int $eid): array
    {
        $hojas = [];
        foreach ((new InformeAsambleaExport($eid))->sheets() as $hoja) {
            $filas = $hoja->array();
            $hojas[$hoja->title()] = $hoja->title() === 'Asistencia' ? $this->sinEmpatesAmbiguos($filas, 0) : $filas;
        }

        return ['base_turning' => $this->baseTurning($eid), 'informe' => $hojas];
    }

    private function baseTurning(int $eid): array
    {
        session(['active_event_id' => $eid]);
        $t = Livewire::test(BaseTurning::class);
        $nominal = $t->get('tipoQuorum') === 'nominal';
        $clave = $nominal ? 'propietario' : 'control_numero';

        $lineas = $t->get('copyText') === '' ? [] : explode("\n", $t->get('copyText'));

        return [
            'tipo' => $t->get('tipoQuorum'),
            'total' => $t->get('totalCoef'),
            'rows' => $this->sinEmpatesAmbiguos($t->get('rows'), $clave),
            'copyText' => $this->sinEmpatesAmbiguos(array_map(fn ($l) => explode("\t", $l), $lineas), $nominal ? 3 : 1),
        ];
    }

    /**
     * El ORDER BY original no define el orden entre filas con la misma clave (por ejemplo las
     * dos filas de un registro duplicado). Se ordenan solo esos empates; el resto conserva
     * exactamente el orden de la consulta.
     */
    private function sinEmpatesAmbiguos(array $filas, string|int $clave): array
    {
        $salida = [];
        $grupo = [];
        $anterior = null;
        foreach ($filas as $i => $f) {
            $k = is_array($f) && array_key_exists($clave, $f) ? (string) $f[$clave] : "#{$i}";
            if ($grupo && $k !== $anterior) {
                usort($grupo, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
                array_push($salida, ...$grupo);
                $grupo = [];
            }
            $grupo[] = $f;
            $anterior = $k;
        }
        usort($grupo, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));
        array_push($salida, ...$grupo);

        return $salida;
    }

    private function evento(string $titulo, string $tipo): int
    {
        return DB::table('eventos')->insertGetId([
            'titulo' => $titulo, 'slug' => str($titulo)->slug(), 'fecha_inicio' => '2026-10-15 08:00:00',
            'activo' => 1, 'is_active' => 0, 'quorum_max' => 0, 'tipo_quorum' => $tipo,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function controles(int $eid, string $prefijo, int $cantidad): array
    {
        $ids = [];
        for ($n = 1; $n <= $cantidad; $n++) {
            $ids[$n] = DB::table('controles')->insertGetId(['evento_id' => $eid, 'numero' => $n, 'serial' => sprintf('%s-%04d', $prefijo, $n), 'estado' => 'LIBRE']);
        }

        return $ids;
    }

    private function registro(array $datos): int
    {
        return DB::table('registros_checkin')->insertGetId($datos + [
            'asistente_telefono' => '3000000000', 'checked_in_at' => '2026-10-15 08:30:00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function sembrarEscenarios(): void
    {
        // ---- Evento A: modo coeficiente --------------------------------------------
        $a = $this->ev['coeficiente_a'] = $this->evento('Escenario A coeficiente', 'coeficiente');
        $padron = [];
        foreach ([['T1-101', 'Propietario Uno', 20], ['T1-102', 'Propietario Dos', 15], ['T1-201', 'Propietario Tres', 10],
                  ['T1-202', 'Propietario Cuatro', 12.5], ['T2-101', 'Propietario Cinco', 7.5], ['T2-102', 'Propietario Seis', 9],
                  ['T2-201', 'Propietario Siete', 11], ['T2-202', 'Propietario Ocho', 8], ['T3-101', 'Propietario Nueve', 7]] as [$inm, $prop, $coef]) {
            $padron[$inm] = DB::table('evento_padron')->insertGetId(['evento_id' => $a, 'inmueble' => $inm, 'propietario' => $prop, 'coeficiente' => $coef]);
        }
        // Grupos: T1-101 representa a T1-102; T1-202 representa a T2-101; el resto solos.
        $grupo = [];
        foreach (['T1-101' => ['T1-102'], 'T1-201' => [], 'T1-202' => ['T2-101'], 'T2-102' => [], 'T2-201' => [], 'T2-202' => [], 'T3-101' => []] as $cabeza => $poderes) {
            $g = $grupo[$cabeza] = DB::table('representacion_grupos')->insertGetId(['evento_id' => $a, 'cabeza_padron_id' => $padron[$cabeza]]);
            DB::table('representacion_miembros')->insert(['evento_id' => $a, 'grupo_id' => $g, 'padron_id' => $padron[$cabeza], 'es_cabeza' => 1]);
            foreach ($poderes as $p) {
                DB::table('representacion_miembros')->insert(['evento_id' => $a, 'grupo_id' => $g, 'padron_id' => $padron[$p], 'es_cabeza' => 0]);
            }
        }
        $ca = $this->controles($a, 'A', 10);

        // Control por id + snapshot, retirado y reingresado (sigue CHECKED_IN).
        $this->registro(['evento_id' => $a, 'grupo_id' => $grupo['T1-101'], 'inmueble_base_id' => $padron['T1-101'], 'estado' => 'CHECKED_IN', 'asistente_nombre' => 'Asistente Uno',
            'control_id' => $ca[1], 'control_numero_snapshot' => 1, 'control_serial_snapshot' => 'A-0001', 'coef_total_snapshot' => 35, 'cabeza_inmueble_snapshot' => 'T1-101',
            'retirado_at' => '2026-10-15 09:00:00', 'reingreso_at' => '2026-10-15 09:30:00']);
        // Sin control_id: el serial sale por el número snapshot.
        $this->registro(['evento_id' => $a, 'grupo_id' => $grupo['T1-201'], 'inmueble_base_id' => $padron['T1-201'], 'estado' => 'CHECKED_IN',
            'control_id' => null, 'control_numero_snapshot' => 2, 'control_serial_snapshot' => null, 'coef_total_snapshot' => 10, 'cabeza_inmueble_snapshot' => 'T1-201']);
        // Retirado, serial por control_id.
        $this->registro(['evento_id' => $a, 'grupo_id' => $grupo['T1-202'], 'inmueble_base_id' => $padron['T1-202'], 'estado' => 'RETIRADO',
            'control_id' => $ca[3], 'control_numero_snapshot' => 3, 'control_serial_snapshot' => null, 'coef_total_snapshot' => 20, 'cabeza_inmueble_snapshot' => 'T1-202',
            'retirado_at' => '2026-10-15 09:15:00']);
        // Control reemplazado (el 4 quedó LIBRE, ahora tiene el 7).
        $this->registro(['evento_id' => $a, 'grupo_id' => $grupo['T2-102'], 'inmueble_base_id' => $padron['T2-102'], 'estado' => 'CHECKED_IN',
            'control_id' => $ca[7], 'control_numero_snapshot' => 7, 'control_serial_snapshot' => 'A-0007', 'coef_total_snapshot' => 9, 'cabeza_inmueble_snapshot' => 'T2-102']);
        // Sin coincidencia: número inexistente y sin control_id.
        $this->registro(['evento_id' => $a, 'grupo_id' => $grupo['T2-201'], 'inmueble_base_id' => $padron['T2-201'], 'estado' => 'CHECKED_IN',
            'control_id' => null, 'control_numero_snapshot' => 99, 'control_serial_snapshot' => null, 'coef_total_snapshot' => 11, 'cabeza_inmueble_snapshot' => 'T2-201']);
        // control_id (5) y número snapshot (6) apuntan a controles distintos.
        $this->registro(['evento_id' => $a, 'grupo_id' => $grupo['T2-202'], 'inmueble_base_id' => $padron['T2-202'], 'estado' => 'CHECKED_IN',
            'control_id' => $ca[5], 'control_numero_snapshot' => 6, 'control_serial_snapshot' => null, 'coef_total_snapshot' => 8, 'cabeza_inmueble_snapshot' => null]);
        // Poder en proceso: no debe aparecer.
        $this->registro(['evento_id' => $a, 'grupo_id' => $grupo['T1-101'], 'inmueble_base_id' => $padron['T1-102'], 'estado' => 'EN_PROCESO', 'checked_in_at' => null, 'coef_total_snapshot' => 0]);
        DB::table('controles')->whereIn('id', [$ca[1], $ca[3], $ca[5], $ca[7]])->update(['estado' => 'ASIGNADO']);

        // ---- Evento B: coeficiente con los mismos números de control que A ---------
        $b = $this->ev['coeficiente_b'] = $this->evento('Escenario B coeficiente', 'coeficiente');
        $pb1 = DB::table('evento_padron')->insertGetId(['evento_id' => $b, 'inmueble' => 'T1-101', 'propietario' => 'Propietario B Uno', 'coeficiente' => 60]);
        $pb2 = DB::table('evento_padron')->insertGetId(['evento_id' => $b, 'inmueble' => 'T1-102', 'propietario' => 'Propietario B Dos', 'coeficiente' => 40]);
        $cb = $this->controles($b, 'B', 3);
        // control_id apunta a un control de OTRO evento: solo vale el del mismo evento por número.
        $this->registro(['evento_id' => $b, 'inmueble_base_id' => $pb1, 'estado' => 'CHECKED_IN', 'control_id' => $ca[9], 'control_numero_snapshot' => 1,
            'control_serial_snapshot' => null, 'coef_total_snapshot' => 60, 'cabeza_inmueble_snapshot' => 'T1-101']);
        $this->registro(['evento_id' => $b, 'inmueble_base_id' => $pb2, 'estado' => 'CHECKED_IN', 'control_id' => $cb[2], 'control_numero_snapshot' => 2,
            'control_serial_snapshot' => 'B-0002', 'coef_total_snapshot' => 40, 'cabeza_inmueble_snapshot' => 'T1-102']);

        // ---- Evento C: modo nominal --------------------------------------------------
        $c = $this->ev['nominal_c'] = $this->evento('Escenario C nominal', 'nominal');
        $per = [];
        foreach ([['1001', 'Ana Ficticia'], ['1002', 'Bruno Ficticio'], ['1003', 'Carla Ficticia'], ['1004', 'Diego Ficticio'],
                  ['1005', 'Elena Ficticia'], ['1006', 'Fabio Ficticio'], ['1007', 'Gina Ficticia']] as [$ced, $nom]) {
            $per[$nom] = DB::table('evento_personas')->insertGetId(['evento_id' => $c, 'cedula' => $ced, 'nombre' => $nom]);
        }
        $gn = [];
        foreach (['Ana Ficticia' => ['Bruno Ficticio'], 'Carla Ficticia' => [], 'Diego Ficticio' => [], 'Elena Ficticia' => [], 'Fabio Ficticio' => [], 'Gina Ficticia' => []] as $cabeza => $poderes) {
            $g = $gn[$cabeza] = DB::table('representacion_grupos_nominal')->insertGetId(['evento_id' => $c, 'cabeza_persona_id' => $per[$cabeza]]);
            DB::table('representacion_miembros_nominal')->insert(['evento_id' => $c, 'grupo_id' => $g, 'persona_id' => $per[$cabeza], 'es_cabeza' => 1]);
            foreach ($poderes as $p) {
                DB::table('representacion_miembros_nominal')->insert(['evento_id' => $c, 'grupo_id' => $g, 'persona_id' => $per[$p], 'es_cabeza' => 0]);
            }
        }
        $cc = $this->controles($c, 'C', 6);
        $this->registro(['evento_id' => $c, 'grupo_id' => $gn['Ana Ficticia'], 'persona_id' => $per['Ana Ficticia'], 'estado' => 'CHECKED_IN', 'control_id' => $cc[1],
            'control_numero_snapshot' => 1, 'control_serial_snapshot' => 'C-0001', 'coef_total_snapshot' => 2]);
        $this->registro(['evento_id' => $c, 'grupo_id' => $gn['Carla Ficticia'], 'persona_id' => $per['Carla Ficticia'], 'estado' => 'CHECKED_IN', 'control_id' => null,
            'control_numero_snapshot' => 2, 'control_serial_snapshot' => null, 'coef_total_snapshot' => 1]);
        $this->registro(['evento_id' => $c, 'grupo_id' => $gn['Diego Ficticio'], 'persona_id' => $per['Diego Ficticio'], 'estado' => 'RETIRADO', 'control_id' => $cc[3],
            'control_numero_snapshot' => 3, 'control_serial_snapshot' => null, 'coef_total_snapshot' => 1, 'retirado_at' => '2026-10-15 09:20:00']);
        $this->registro(['evento_id' => $c, 'grupo_id' => $gn['Elena Ficticia'], 'persona_id' => $per['Elena Ficticia'], 'estado' => 'CHECKED_IN', 'control_id' => $cc[4],
            'control_numero_snapshot' => 5, 'control_serial_snapshot' => null, 'coef_total_snapshot' => 1]);
        $this->registro(['evento_id' => $c, 'grupo_id' => $gn['Fabio Ficticio'], 'persona_id' => $per['Fabio Ficticio'], 'estado' => 'CHECKED_IN', 'control_id' => null,
            'control_numero_snapshot' => 77, 'control_serial_snapshot' => null, 'coef_total_snapshot' => 1]);
        $this->registro(['evento_id' => $c, 'grupo_id' => $gn['Ana Ficticia'], 'persona_id' => $per['Bruno Ficticio'], 'estado' => 'EN_PROCESO', 'checked_in_at' => null, 'coef_total_snapshot' => 0]);
    }
}
