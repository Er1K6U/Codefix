<?php

namespace App\Livewire\Checkin;

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use App\Services\ControlService;

class RegistroPantalla extends Component
{
    public string $search = '';
    public array $results = [];

    public ?int $registroId = null;

    // datos del “header”
    public ?int $eventoId = 1; // por ahora fijo, luego lo amarramos a station/evento activo
    public ?int $inmuebleBaseId = null;
    public ?string $estado = null;
    public ?int $controlNumero = null;
    public ?string $inmuebleLabel = null;

    // asistente
    public ?string $asistenteNombre = null;
    public ?string $asistenteTelefono = null;
    public ?string $asistenteCorreo = null;

    public ?string $errorTelefono = null;

    // Mensajes del flujo de selección (Check-in)
    public ?string $checkinMsg = null;
    public ?string $checkinError = null;

    // "originales" para detectar cambios sin guardar
    public ?string $asistenteNombreOriginal = null;
    public ?string $asistenteTelefonoOriginal = null;
    public ?string $asistenteCorreoOriginal = null;

    public bool $confirmDiscard = false;
    public ?string $pendingAction = null; // 'clear' o 'select'

    // para recordar a qué inmueble quería ir cuando aparece el modal
    public ?int $pendingInmuebleId = null;

    // ---- Control ----
    public ?string $controlNumeroInput = null;
    public ?string $controlMsg = null;
    public ?string $controlError = null;

    // ---- Representación / Poderes ----
    public ?int $grupoId = null;
    public bool $isCabezaSeleccionada = false;

    public array $miembros = []; // lista para UI (cabeza + poderes)
    public ?float $coefTotal = null;

    // Métrica: cuántos poderes (sin incluir cabeza)
    public ?int $poderCount = null;

    public string $poderSearch = '';
    public array $poderResults = [];

    public ?string $poderError = null;
    public ?string $poderMsg = null;
    public ?int $requestedPadronId = null;


    public function updatedSearch(): void
    {
        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            $this->results = [];
            return;
        }

        $rows = DB::table('evento_padron')
            ->select('id', 'inmueble')
            ->where('evento_id', $this->eventoId)
            ->where('inmueble', 'like', "%{$term}%")
            ->limit(10)
            ->get();

        $this->results = $rows->map(fn($r) => [
            'id' => (int) $r->id,
            'label' => (string) $r->inmueble,
        ])->toArray();
    }

    /**
     * ✅ selección protegida desde las sugerencias
     */
    public function requestSelectInmueble(int $inmuebleId): void
    {
        if ($this->registroId && $this->hasUnsavedChanges()) {
            $this->confirmDiscard = true;
            $this->pendingAction = 'select';
            $this->pendingInmuebleId = $inmuebleId;
            return;
        }

        $this->selectInmueble($inmuebleId);
    }

    public function selectInmueble(int $inmuebleId): void
    {
        // ✅ reset UI de mini-componentes para no dejar "rastros"
        $this->resetControlUi();
        $this->resetPoderUi();

        // ✅ Resolver antes de crear/usar registros_checkin:
        // si el inmueble buscado es PODER, abrimos la cabeza del grupo.
        [$targetId, $msg] = $this->resolveCheckinTarget($inmuebleId);

        $this->checkinMsg = $msg;     // si ya lo tienes en el componente
        $this->checkinError = null;   // si ya lo tienes en el componente

        $inmuebleId = (int) $targetId;

        // ✅ reset mensajes selección
        $this->checkinMsg = null;
        $this->checkinError = null;
        $this->requestedPadronId = $inmuebleId;

        // ✅ Seguridad: si el inmueble buscado ya está representado como MIEMBRO en un grupo,
        // y NO es la cabeza, entonces abrimos automáticamente la cabeza del grupo.
        $miembro = DB::table('representacion_miembros')
            ->where('padron_id', $inmuebleId)
            ->first();

        if ($miembro) {
            $grupo = DB::table('representacion_grupos')
                ->where('id', (int) $miembro->grupo_id)
                ->where('evento_id', $this->eventoId)
                ->first();

            if ($grupo && !empty($grupo->cabeza_padron_id)) {
                $headId = (int) $grupo->cabeza_padron_id;

                if ($headId !== (int) $inmuebleId) {
                    $this->checkinMsg = "ℹ️ El inmueble {$this->labelInmueble($inmuebleId)} ya está representado en el grupo cuya cabeza es {$this->labelInmueble($headId)}. Abriendo la cabeza automáticamente…";
                    $inmuebleId = $headId; // 👈 nos vamos directo a la cabeza
                }
            }
        }

        // 1) Registro checkin
        $registro = DB::table('registros_checkin')
            ->where('evento_id', $this->eventoId)
            ->where('inmueble_base_id', $inmuebleId)
            ->first();

        if (!$registro) {
            $newId = DB::table('registros_checkin')->insertGetId([
                'evento_id' => $this->eventoId,
                'grupo_id' => 1, // lo actualizamos abajo cuando creemos/encontremos grupo real
                'inmueble_base_id' => $inmuebleId,
                'estado' => 'EN_PROCESO',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $registro = DB::table('registros_checkin')->where('id', $newId)->first();
        }

        $this->registroId = (int) $registro->id;
        $this->inmuebleBaseId = (int) $registro->inmueble_base_id;
        $this->estado = (string) $registro->estado;

        // 2) Label inmueble
        $padron = DB::table('evento_padron')
            ->select('id', 'inmueble', 'propietario', 'coeficiente', 'asistente', 'celular_asistente', 'correo_asistente')
            ->where('evento_id', $this->eventoId)
            ->where('id', $this->inmuebleBaseId)
            ->first();

        $this->inmuebleLabel = $padron?->inmueble ?? ("Inmueble #{$this->inmuebleBaseId}");

        // 3) Cargar asistente (del registro si existe, si no, precarga desde padrón)
        $this->asistenteNombre = $registro->asistente_nombre ?? ($padron->asistente ?? null);
        $this->asistenteTelefono = $registro->asistente_telefono ?? ($padron->celular_asistente ?? null);
        $this->asistenteCorreo = $registro->asistente_correo ?? ($padron->correo_asistente ?? null);
        $this->errorTelefono = null;

        // originales
        $this->asistenteNombreOriginal = $this->asistenteNombre;
        $this->asistenteTelefonoOriginal = $this->asistenteTelefono;
        $this->asistenteCorreoOriginal = $this->asistenteCorreo;

        $this->confirmDiscard = false;
        $this->pendingAction = null;
        $this->pendingInmuebleId = null;

        // 4) Control asignado (si existe)
        $this->controlNumero = null;
        if (!is_null($registro->control_id)) {
            $control = DB::table('controles')->select('numero')->where('id', $registro->control_id)->first();
            $this->controlNumero = $control?->numero ?? null;
        }

        // 5) ✅ Representación: asegurar grupo real para ESTE inmueble (sin coronar cabezas indebidas)
        $this->ensureGrupoForCabeza($this->inmuebleBaseId);

        // 6) Limpiar búsqueda principal
        $this->search = '';
        $this->results = [];

        // Si no hubo redirección, no dejamos msg viejo pegado
        if (!$msg) {
            $this->checkinMsg = null;
        }
    }


    private function resolveCheckinTarget(int $requestedPadronId): array
    {
        // Retorna: [targetPadronId, msg|null]
        $miembro = DB::table('representacion_miembros as rm')
            ->join('representacion_grupos as rg', 'rg.id', '=', 'rm.grupo_id')
            ->where('rm.padron_id', $requestedPadronId)
            ->select('rm.grupo_id', 'rg.cabeza_padron_id')
            ->first();

        if (!$miembro) {
            // No pertenece a ningún grupo (caso raro, pero lo dejamos igual)
            return [$requestedPadronId, null];
        }

        $headId = (int) $miembro->cabeza_padron_id;

        if ($headId > 0 && $headId !== (int) $requestedPadronId) {
            $reqLabel = $this->labelInmueble($requestedPadronId);
            $headLabel = $this->labelInmueble($headId);

            return [$headId, "ℹ️ El inmueble {$reqLabel} está representado en el grupo cuya cabeza es {$headLabel}. Se abrirá la cabeza automáticamente."];
        }

        return [$requestedPadronId, null];
    }

    private function ensureGrupoForCabeza(int $padronId): void
    {
        // OJO: Este método asegura "el grupo correcto para ESTE inmueble"
        // y NO debe promover a cabeza si el inmueble ya es poder en un grupo.

        // 0) Si ya existe como miembro (padron_id es UNIQUE global), ese grupo manda.
        $miembroGlobal = DB::table('representacion_miembros')
            ->where('padron_id', $padronId)
            ->first();

        if ($miembroGlobal) {
            $this->grupoId = (int) $miembroGlobal->grupo_id;

            // Validar que el grupo exista
            $grupo = DB::table('representacion_grupos')
                ->where('id', $this->grupoId)
                ->first();

            // Caso rarísimo: existe miembro pero no grupo => recreamos grupo mínimo
            // (Aquí no hay forma "perfecta" de saber la cabeza original, así que dejamos al padronId como cabeza)
            if (!$grupo) {
                $gid = DB::table('representacion_grupos')->insertGetId([
                    'evento_id' => $this->eventoId,
                    'cabeza_padron_id' => $padronId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('representacion_miembros')
                    ->where('id', $miembroGlobal->id)
                    ->update([
                        'grupo_id' => $gid,
                        'es_cabeza' => 1,
                        'updated_at' => now(),
                    ]);

                $this->grupoId = (int) $gid;
                $grupo = DB::table('representacion_grupos')->where('id', $this->grupoId)->first();
            }

            // Cabeza real definida por el grupo (NO por el inmueble que están buscando)
            $headPadronId = (int) ($grupo->cabeza_padron_id ?? 0);

            // Si el grupo no tiene cabeza definida, en ese caso sí la definimos con este padronId.
            if ($headPadronId <= 0) {
                DB::table('representacion_grupos')
                    ->where('id', $this->grupoId)
                    ->update(['cabeza_padron_id' => $padronId, 'updated_at' => now()]);

                $headPadronId = $padronId;
            }

            // Asegurar que exista el miembro cabeza (si falta por datos raros)
            $headMember = DB::table('representacion_miembros')
                ->where('grupo_id', $this->grupoId)
                ->where('padron_id', $headPadronId)
                ->first();

            if (!$headMember) {
                try {
                    DB::table('representacion_miembros')->insert([
                        'grupo_id' => $this->grupoId,
                        'padron_id' => $headPadronId,
                        'es_cabeza' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } catch (\Throwable $e) {
                    // no rompemos la UI
                }
            }

            // ✅ SANEO: dejar SOLO 1 cabeza en el grupo (la del grupo->cabeza_padron_id)
            DB::table('representacion_miembros')
                ->where('grupo_id', $this->grupoId)
                ->where('padron_id', '!=', $headPadronId)
                ->where('es_cabeza', 1)
                ->update(['es_cabeza' => 0, 'updated_at' => now()]);

            DB::table('representacion_miembros')
                ->where('grupo_id', $this->grupoId)
                ->where('padron_id', $headPadronId)
                ->update(['es_cabeza' => 1, 'updated_at' => now()]);
        } else {
            // 1) Si no existe miembro global, entonces sí creamos/aseguramos grupo base + cabeza
            $grupo = DB::table('representacion_grupos')
                ->where('evento_id', $this->eventoId)
                ->where('cabeza_padron_id', $padronId)
                ->first();

            if (!$grupo) {
                $gid = DB::table('representacion_grupos')->insertGetId([
                    'evento_id' => $this->eventoId,
                    'cabeza_padron_id' => $padronId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('representacion_miembros')->insert([
                    'grupo_id' => $gid,
                    'padron_id' => $padronId,
                    'es_cabeza' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->grupoId = (int) $gid;
            } else {
                $this->grupoId = (int) $grupo->id;

                $head = DB::table('representacion_miembros')
                    ->where('grupo_id', $this->grupoId)
                    ->where('padron_id', $padronId)
                    ->first();

                if (!$head) {
                    DB::table('representacion_miembros')->insert([
                        'grupo_id' => $this->grupoId,
                        'padron_id' => $padronId,
                        'es_cabeza' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                } else {
                    // asegurar cabeza
                    DB::table('representacion_miembros')
                        ->where('id', $head->id)
                        ->update(['es_cabeza' => 1, 'updated_at' => now()]);
                }

                // por seguridad: nadie más debe quedar como cabeza
                DB::table('representacion_miembros')
                    ->where('grupo_id', $this->grupoId)
                    ->where('padron_id', '!=', $padronId)
                    ->where('es_cabeza', 1)
                    ->update(['es_cabeza' => 0, 'updated_at' => now()]);
            }
        }

        // 2) Amarrar registro_checkin.grupo_id al grupo real
        if ($this->registroId && $this->grupoId) {
            DB::table('registros_checkin')
                ->where('id', $this->registroId)
                ->update(['grupo_id' => $this->grupoId, 'updated_at' => now()]);
        }
        // ✅ Bandera: ¿el inmueble seleccionado es la cabeza del grupo?
        $grupo = null;
        if ($this->grupoId) {
            $grupo = DB::table('representacion_grupos')
                ->select('cabeza_padron_id')
                ->where('id', $this->grupoId)
                ->first();
        }

        $this->isCabezaSeleccionada = $grupo && ((int) $grupo->cabeza_padron_id === (int) $this->inmuebleBaseId);
        $this->loadMiembros();
    }

    private function loadMiembros(): void
    {
        if (!$this->grupoId) {
            $this->miembros = [];
            $this->coefTotal = null;
            $this->poderCount = null;
            return;
        }

        $rows = DB::table('representacion_miembros as rm')
            ->join('evento_padron as ep', 'ep.id', '=', 'rm.padron_id')
            ->where('rm.grupo_id', $this->grupoId)
            ->select(
                'rm.id as miembro_id',
                'rm.padron_id',
                'rm.es_cabeza',
                'ep.inmueble',
                'ep.propietario',
                'ep.coeficiente'
            )
            ->orderByDesc('rm.es_cabeza')
            ->orderBy('ep.inmueble')
            ->get();

        $this->miembros = $rows->map(fn($r) => [
            'miembro_id' => (int) $r->miembro_id,
            'padron_id' => (int) $r->padron_id,
            'es_cabeza' => (int) $r->es_cabeza === 1,
            'inmueble' => (string) $r->inmueble,
            'propietario' => (string) $r->propietario,
            'coeficiente' => (float) $r->coeficiente,
        ])->toArray();

        $this->coefTotal = round(array_sum(array_map(fn($m) => (float) $m['coeficiente'], $this->miembros)), 4);
        $this->poderCount = count(array_filter($this->miembros, fn($m) => !$m['es_cabeza']));
    }

    public function updatedPoderSearch(): void
    {
        $term = trim($this->poderSearch);

        if (!$this->registroId || !$this->grupoId) {
            $this->poderResults = [];
            return;
        }

        if (mb_strlen($term) < 2) {
            $this->poderResults = [];
            return;
        }

        $rows = DB::table('evento_padron')
            ->select('id', 'inmueble', 'propietario', 'coeficiente')
            ->where('evento_id', $this->eventoId)
            ->where(function ($q) use ($term) {
                $q->where('inmueble', 'like', "%{$term}%")
                    ->orWhere('propietario', 'like', "%{$term}%");
            })
            ->limit(8)
            ->get();

        $this->poderResults = $rows->map(fn($r) => [
            'id' => (int) $r->id,
            'label' => (string) $r->inmueble,
            'propietario' => (string) $r->propietario,
            'coef' => (float) $r->coeficiente,
        ])->toArray();
    }

    private function labelInmueble(int $padronId): string
    {
        $p = DB::table('evento_padron')
            ->select('inmueble')
            ->where('evento_id', $this->eventoId)
            ->where('id', $padronId)
            ->first();

        return $p?->inmueble ?? (string) $padronId;
    }

    public function addPoder(int $padronId): void
    {
        $this->poderError = null;
        $this->poderMsg = null;

        if (!$this->grupoId) {
            $this->poderError = 'Primero selecciona un inmueble.';
            return;
        }

        // ❌ No se puede agregarse a sí mismo
        if ((int) $padronId === (int) $this->inmuebleBaseId) {
            $this->poderError = 'Ese inmueble ya es la cabeza del grupo.';
            return;
        }

        // 🔒 Transacción para evitar carreras (sin transferencias de control)
        $result = DB::transaction(function () use ($padronId) {

            // Lock del miembro global (UNIQUE padron_id)
            $exists = DB::table('representacion_miembros')
                ->where('padron_id', $padronId)
                ->lockForUpdate()
                ->first();

            // Caso A: no existía, lo insertamos como poder
            if (!$exists) {
                DB::table('representacion_miembros')->insert([
                    'grupo_id' => $this->grupoId,
                    'padron_id' => $padronId,
                    'es_cabeza' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['status' => 'inserted'];
            }

            $grupoOrigenId = (int) $exists->grupo_id;

            // Caso B: ya está en este mismo grupo
            if ($grupoOrigenId === (int) $this->grupoId) {
                return ['status' => 'already_in_group'];
            }

            // Contar miembros del grupo origen
            $countOrigen = (int) DB::table('representacion_miembros')
                ->where('grupo_id', $grupoOrigenId)
                ->lockForUpdate()
                ->count();

            // Caso C: grupo origen tiene más de 1 miembro => no movemos
            if ($countOrigen > 1) {
                return ['status' => 'origin_has_members'];
            }

            // ✅ Caso D: grupo origen está "solo"
            // REGLA NUEVA: si el inmueble (origen) ya tiene CONTROL asignado, NO se puede mover/anexar.
            // Eso significa que ese inmueble ya hizo check-in operativo y debe ser la cabeza.
            $registroOrigen = DB::table('registros_checkin')
                ->where('evento_id', $this->eventoId)
                ->where('inmueble_base_id', $padronId)
                ->lockForUpdate()
                ->first();

            if ($registroOrigen && !is_null($registroOrigen->control_id)) {
                $controlOrigen = DB::table('controles')
                    ->where('id', $registroOrigen->control_id)
                    ->select('numero')
                    ->first();

                return [
                    'status' => 'origin_has_control',
                    'origin_control_num' => $controlOrigen?->numero,
                ];
            }

            // Si NO tiene control, entonces sí lo movemos al grupo actual
            DB::table('representacion_miembros')
                ->where('id', $exists->id)
                ->update([
                    'grupo_id' => $this->grupoId,
                    'es_cabeza' => 0,
                    'updated_at' => now(),
                ]);

            // Eliminar grupo origen (queda vacío)
            DB::table('representacion_grupos')
                ->where('id', $grupoOrigenId)
                ->delete();

            return ['status' => 'moved_from_empty_origin'];
        });

        // Mensajes UI según resultado
        $inm = $this->labelInmueble($padronId);
        $cabezaActual = $this->labelInmueble((int) $this->inmuebleBaseId);

        if ($result['status'] === 'already_in_group') {
            $this->poderMsg = "ℹ️ El inmueble {$inm} ya está agregado en este grupo.";
        } elseif ($result['status'] === 'origin_has_members') {
            $this->poderError = "Ese inmueble ({$inm}) ya está representado en otro grupo que tiene poderes asociados. (Luego habilitamos moverlo con autorización).";
        } elseif ($result['status'] === 'origin_has_control') {
            $num = $result['origin_control_num'] ?? '—';
            $this->poderError = "No se puede anexar {$inm} porque ya tiene CHECK-IN / control #{$num}. En este caso la cabeza debe ser {$inm}. Abre {$inm} y allí anexas {$cabezaActual} como poder.";
        } else {
            $this->poderMsg = "✅ Poder anexado: inmueble {$inm}.";
        }

        // Refrescar UI
        $this->poderSearch = '';
        $this->poderResults = [];
        $this->loadMiembros();

        // Refrescar control en header (por si acaso)
        if ($this->registroId) {
            $registro = DB::table('registros_checkin')->select('control_id')->where('id', $this->registroId)->first();
            $this->controlNumero = null;

            if ($registro && !is_null($registro->control_id)) {
                $control = DB::table('controles')->select('numero')->where('id', $registro->control_id)->first();
                $this->controlNumero = $control?->numero ?? null;
            }
        }
    }

    public function removePoder(int $miembroId): void
    {
        $this->poderError = null;
        $this->poderMsg = null;

        $result = DB::transaction(function () use ($miembroId) {

            // 1) Lock del miembro para evitar carreras
            $m = DB::table('representacion_miembros')
                ->where('id', $miembroId)
                ->lockForUpdate()
                ->first();

            if (!$m) {
                return ['status' => 'not_found'];
            }

            if ((int) $m->es_cabeza === 1) {
                return ['status' => 'is_head'];
            }

            $padronId = (int) $m->padron_id;

            // 2) Eliminar el poder del grupo actual
            DB::table('representacion_miembros')
                ->where('id', $miembroId)
                ->delete();

            // 3) ✅ Re-crear / asegurar grupo base para ese inmueble (cabeza = él mismo)
            $grupoBase = DB::table('representacion_grupos')
                ->where('evento_id', $this->eventoId)
                ->where('cabeza_padron_id', $padronId)
                ->lockForUpdate()
                ->first();

            if (!$grupoBase) {
                $gid = DB::table('representacion_grupos')->insertGetId([
                    'evento_id' => $this->eventoId,
                    'cabeza_padron_id' => $padronId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                $gid = (int) $grupoBase->id;
            }

            // 4) Asegurar que exista el miembro cabeza en su grupo base
            $head = DB::table('representacion_miembros')
                ->where('grupo_id', $gid)
                ->where('padron_id', $padronId)
                ->lockForUpdate()
                ->first();

            if (!$head) {
                DB::table('representacion_miembros')->insert([
                    'grupo_id' => $gid,
                    'padron_id' => $padronId,
                    'es_cabeza' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                // por si estaba raro
                DB::table('representacion_miembros')
                    ->where('id', $head->id)
                    ->update(['es_cabeza' => 1, 'updated_at' => now()]);
            }

            // 5) Por seguridad: nadie más debe quedar como cabeza en ese grupo base
            DB::table('representacion_miembros')
                ->where('grupo_id', $gid)
                ->where('padron_id', '!=', $padronId)
                ->where('es_cabeza', 1)
                ->update(['es_cabeza' => 0, 'updated_at' => now()]);

            // 6) Si existe registro_checkin de ese inmueble, lo amarramos al grupo base
            DB::table('registros_checkin')
                ->where('evento_id', $this->eventoId)
                ->where('inmueble_base_id', $padronId)
                ->update([
                    'grupo_id' => $gid,
                    'updated_at' => now(),
                ]);

            return ['status' => 'ok', 'padron_id' => $padronId];
        });

        if ($result['status'] === 'not_found') {
            return;
        }

        if ($result['status'] === 'is_head') {
            $this->poderError = 'No puedes quitar la cabeza del grupo.';
            return;
        }

        // ✅ Mensaje bonito
        $inm = $this->labelInmueble((int) $result['padron_id']);
        $this->poderMsg = "🗑️ Poder removido. ({$inm} volvió a su grupo base como cabeza)";

        // refrescar UI del grupo actual
        $this->loadMiembros();
    }

    public function separarCabeza(): void
    {
        // Mensajería (usa lo que ya estés mostrando en Blade)
        // Si ya tienes $checkinMsg / $checkinError, perfecto:
        $this->checkinMsg = null;
        $this->checkinError = null;

        if (!$this->registroId || !$this->grupoId || !$this->inmuebleBaseId) {
            $this->checkinError = 'Primero selecciona un inmueble.';
            return;
        }

        // Debe ser cabeza (según tu bandera ya existente en el componente)
        if (property_exists($this, 'isCabezaSeleccionada') && !$this->isCabezaSeleccionada) {
            $this->checkinError = 'Solo puedes separar desde la cabeza del grupo.';
            return;
        }

        // Debe haber al menos 2 miembros para poder promover a alguien
        if (count($this->miembros) < 2) {
            $this->checkinError = 'Este grupo no tiene poderes. No hay a quién promover como nueva cabeza.';
            return;
        }

        $eventoId = (int) $this->eventoId;
        $grupoId = (int) $this->grupoId;

        // Para que la UI no quede rara si hay inputs activos
        $this->resetControlUi();
        $this->resetPoderUi();

        try {
            $out = DB::transaction(function () use ($eventoId, $grupoId) {

                // 1) Lock del grupo
                $grupo = DB::table('representacion_grupos')
                    ->where('id', $grupoId)
                    ->lockForUpdate()
                    ->first();

                if (!$grupo) {
                    return ['ok' => false, 'msg' => 'No se encontró el grupo.'];
                }

                $oldHeadPadronId = (int) $grupo->cabeza_padron_id;

                // 2) Lock miembros del grupo
                $miembros = DB::table('representacion_miembros')
                    ->where('grupo_id', $grupoId)
                    ->lockForUpdate()
                    ->get();

                if ($miembros->count() < 2) {
                    return ['ok' => false, 'msg' => 'El grupo no tiene poderes para promover.'];
                }

                // 3) Elegir nuevo cabeza: primer miembro que NO sea cabeza (un poder)
                $nuevo = $miembros->firstWhere('es_cabeza', 0);

                if (!$nuevo) {
                    // Si por datos raros todos están como cabeza, elegimos uno distinto al oldHead
                    $nuevo = $miembros->firstWhere('padron_id', '!=', $oldHeadPadronId);
                }

                if (!$nuevo) {
                    return ['ok' => false, 'msg' => 'No fue posible determinar el nuevo cabeza.'];
                }

                $newHeadPadronId = (int) $nuevo->padron_id;

                // 4) Actualizar cabeza en el grupo
                DB::table('representacion_grupos')
                    ->where('id', $grupoId)
                    ->update([
                        'cabeza_padron_id' => $newHeadPadronId,
                        'updated_at' => now(),
                    ]);

                // 5) Dejar una sola cabeza: todos 0
                DB::table('representacion_miembros')
                    ->where('grupo_id', $grupoId)
                    ->update(['es_cabeza' => 0, 'updated_at' => now()]);

                // Nuevo cabeza = 1
                DB::table('representacion_miembros')
                    ->where('grupo_id', $grupoId)
                    ->where('padron_id', $newHeadPadronId)
                    ->update(['es_cabeza' => 1, 'updated_at' => now()]);

                // 6) Control: mover del registro de la cabeza vieja al nuevo cabeza (si aplica)
                $oldRegistro = DB::table('registros_checkin')
                    ->where('evento_id', $eventoId)
                    ->where('inmueble_base_id', $oldHeadPadronId)
                    ->lockForUpdate()
                    ->first();

                $controlNum = null;

                // Asegurar registro del nuevo cabeza
                $newRegistro = DB::table('registros_checkin')
                    ->where('evento_id', $eventoId)
                    ->where('inmueble_base_id', $newHeadPadronId)
                    ->lockForUpdate()
                    ->first();

                if (!$newRegistro) {
                    $newRegistroId = DB::table('registros_checkin')->insertGetId([
                        'evento_id' => $eventoId,
                        'grupo_id' => $grupoId,
                        'inmueble_base_id' => $newHeadPadronId,
                        'estado' => 'EN_PROCESO',
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $newRegistro = DB::table('registros_checkin')
                        ->where('id', $newRegistroId)
                        ->lockForUpdate()
                        ->first();
                } else {
                    // amarrar el grupo del nuevo cabeza
                    DB::table('registros_checkin')
                        ->where('id', $newRegistro->id)
                        ->update(['grupo_id' => $grupoId, 'updated_at' => now()]);
                }

                if ($oldRegistro && !is_null($oldRegistro->control_id)) {

                    // Si el nuevo cabeza ya tuviera control (caso raro), bloqueamos
                    if (!is_null($newRegistro->control_id)) {
                        return [
                            'ok' => false,
                            'msg' => 'No se pudo separar: el nuevo cabeza ya tiene un control asignado.',
                        ];
                    }

                    $control = DB::table('controles')
                        ->where('id', $oldRegistro->control_id)
                        ->lockForUpdate()
                        ->first();

                    if ($control) {
                        $controlNum = (int) $control->numero;

                        // Poner control al nuevo registro
                        DB::table('registros_checkin')
                            ->where('id', $newRegistro->id)
                            ->update([
                                'control_id' => $control->id,
                                'control_numero_snapshot' => $control->numero,
                                'updated_at' => now(),
                            ]);

                        // Apuntar control al nuevo registro
                        DB::table('controles')
                            ->where('id', $control->id)
                            ->update([
                                'estado' => 'ASIGNADO',
                                'asignado_a_registro_id' => $newRegistro->id,
                                'updated_at' => now(),
                            ]);

                        // Limpiar control en registro viejo
                        DB::table('registros_checkin')
                            ->where('id', $oldRegistro->id)
                            ->update([
                                'control_id' => null,
                                'control_numero_snapshot' => null,
                                'updated_at' => now(),
                            ]);
                    }
                }

                // 7) Sacar la cabeza vieja a un grupo base NUEVO
                $newBaseGroupId = DB::table('representacion_grupos')->insertGetId([
                    'evento_id' => $eventoId,
                    'cabeza_padron_id' => $oldHeadPadronId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // mover el miembro de la cabeza vieja a su nuevo grupo, y marcarlo como cabeza
                DB::table('representacion_miembros')
                    ->where('padron_id', $oldHeadPadronId)
                    ->update([
                        'grupo_id' => $newBaseGroupId,
                        'es_cabeza' => 1,
                        'updated_at' => now(),
                    ]);

                // amarrar registro viejo al nuevo grupo base
                if ($oldRegistro) {
                    DB::table('registros_checkin')
                        ->where('id', $oldRegistro->id)
                        ->update(['grupo_id' => $newBaseGroupId, 'updated_at' => now()]);
                }

                return [
                    'ok' => true,
                    'old_head' => $oldHeadPadronId,
                    'new_head' => $newHeadPadronId,
                    'control_num' => $controlNum,
                ];
            });

            if (empty($out['ok'])) {
                $this->checkinError = $out['msg'] ?? 'No fue posible separar la cabeza.';
                return;
            }

            // Mensaje UI
            $old = $this->labelInmueble((int) $out['old_head']);
            $new = $this->labelInmueble((int) $out['new_head']);

            $extra = '';
            if (!empty($out['control_num'])) {
                $extra = " Control #{$out['control_num']} migrado al nuevo cabeza.";
            }

            $this->checkinMsg = "✅ Cabeza separada: {$old} ahora quedó independiente. Nuevo cabeza del grupo: {$new}.{$extra}";

            // ✅ Quedarnos parados en el NUEVO cabeza (para seguir operando el grupo)
            $this->selectInmueble((int) $out['new_head']);

        } catch (\Throwable $e) {
            $this->checkinError = app()->isLocal()
                ? 'Error separando la cabeza: ' . $e->getMessage()
                : 'Ocurrió un error separando la cabeza. Revisa logs.';
            return;
        }
    }

    public function saveAsistente(): void
    {
        $tel = trim((string) $this->asistenteTelefono);

        if ($tel === '') {
            $this->errorTelefono = 'El teléfono es obligatorio para el registro.';
            return;
        }

        DB::table('registros_checkin')
            ->where('id', $this->registroId)
            ->update([
                'asistente_nombre' => $this->asistenteNombre,
                'asistente_telefono' => $this->asistenteTelefono,
                'asistente_correo' => $this->asistenteCorreo,
                'updated_at' => now(),
            ]);

        $this->errorTelefono = null;

        $this->asistenteNombreOriginal = $this->asistenteNombre;
        $this->asistenteTelefonoOriginal = $this->asistenteTelefono;
        $this->asistenteCorreoOriginal = $this->asistenteCorreo;
    }

    public function hasUnsavedChanges(): bool
    {
        return ($this->asistenteNombre ?? '') !== ($this->asistenteNombreOriginal ?? '')
            || ($this->asistenteTelefono ?? '') !== ($this->asistenteTelefonoOriginal ?? '')
            || ($this->asistenteCorreo ?? '') !== ($this->asistenteCorreoOriginal ?? '');
    }

    public function requestClearSelection(): void
    {
        if ($this->hasUnsavedChanges()) {
            $this->confirmDiscard = true;
            $this->pendingAction = 'clear';
            return;
        }

        $this->clearSelection();
    }

    public function discardChangesAndProceed(): void
    {
        $action = $this->pendingAction;
        $nextInmuebleId = $this->pendingInmuebleId;

        $this->confirmDiscard = false;
        $this->pendingAction = null;
        $this->pendingInmuebleId = null;

        if ($action === 'clear') {
            $this->clearSelection();
            return;
        }

        if ($action === 'select' && $nextInmuebleId) {
            $this->clearSelection();
            $this->selectInmueble((int) $nextInmuebleId);
            return;
        }
    }

    public function assignControl(ControlService $controlService): void
    {
        $this->controlMsg = null;
        $this->controlError = null;

        if (!$this->registroId) {
            $this->controlError = 'Primero selecciona un inmueble.';
            return;
        }

        // ✅ Seguridad: el control solo se asigna desde la cabeza del grupo
        if (!$this->isCabezaSeleccionada) {
            $this->controlError = 'Este inmueble es un poder dentro de un grupo. El control debe asignarse desde la cabeza del grupo.';
            return;
        }

        $actual = DB::table('registros_checkin')->select('control_id')->where('id', $this->registroId)->first();
        if ($actual && !is_null($actual->control_id)) {
            $this->controlError = 'Este registro ya tiene un control asignado. (Luego habilitamos cambiar control con autorización).';
            return;
        }

        $raw = trim((string) $this->controlNumeroInput);

        if ($raw === '' || !ctype_digit($raw)) {
            $this->controlError = 'Ingresa un número de control válido (solo números).';
            return;
        }

        $num = (int) $raw;
        if ($num <= 0) {
            $this->controlError = 'El número de control debe ser mayor a 0.';
            return;
        }

        try {
            $controlService->assignByNumero($this->eventoId, $this->registroId, $num);
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->controlError = $e->errors()['control'][0] ?? 'No fue posible asignar el control.';
            return;
        } catch (\Throwable $e) {
            $this->controlError = 'No fue posible asignar el control.';
            return;
        }

        $registro = DB::table('registros_checkin')->select('control_id')->where('id', $this->registroId)->first();
        $this->controlNumero = null;

        if ($registro && !is_null($registro->control_id)) {
            $control = DB::table('controles')->select('numero')->where('id', $registro->control_id)->first();
            $this->controlNumero = $control?->numero ?? null;
        }

        $this->controlMsg = "✅ Control #{$num} asignado correctamente.";
        $this->controlNumeroInput = null;
    }

    private function resetControlUi(): void
    {
        $this->controlNumeroInput = null;
        $this->controlMsg = null;
        $this->controlError = null;
    }

    private function resetPoderUi(): void
    {
        $this->poderSearch = '';
        $this->poderResults = [];
        $this->poderError = null;
        $this->poderMsg = null;
    }

    public function cancelDiscard(): void
    {
        $this->confirmDiscard = false;
        $this->pendingAction = null;
        $this->pendingInmuebleId = null;
    }

    public function clearSelection(): void
    {
        $this->registroId = null;
        $this->inmuebleBaseId = null;
        $this->estado = null;
        $this->controlNumero = null;
        $this->inmuebleLabel = null;

        $this->asistenteNombre = null;
        $this->asistenteTelefono = null;
        $this->asistenteCorreo = null;
        $this->errorTelefono = null;

        $this->checkinMsg = null;
        $this->checkinError = null;

        $this->asistenteNombreOriginal = null;
        $this->asistenteTelefonoOriginal = null;
        $this->asistenteCorreoOriginal = null;

        $this->grupoId = null;
        $this->miembros = [];
        $this->coefTotal = null;
        $this->poderCount = null;

        $this->confirmDiscard = false;
        $this->pendingAction = null;
        $this->pendingInmuebleId = null;

        $this->resetControlUi();
        $this->resetPoderUi();

        $this->search = '';
        $this->results = [];
    }

    public function render()
    {
        return view('livewire.checkin.registro-pantalla')
            ->layout('layouts.app');
    }
}
