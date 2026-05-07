<?php

namespace App\Livewire\Checkin;

use Livewire\Component;
use Illuminate\Support\Facades\DB;
use App\Services\ControlService;
use App\Support\EventContext;

class RegistroPantalla extends Component
{
    public string $search = '';
    public array $results = [];

    public ?int $registroId = null;

    // datos del “header”
    public ?int $eventoId = null; // ✅ ahora viene del contexto del puesto
    public ?int $inmuebleBaseId = null;
    public ?string $estado = null;

    public ?int $controlNumero = null;
    public ?string $controlSerial = null; // ✅ serial/código del control

    public ?string $inmuebleLabel = null;
    public ?string $propietarioLabel = null; // ✅ para la vista
    public ?float $coefInmueble = null;      // ✅ coeficiente SOLO del inmueble


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

    // ✅ Cambios pendientes por poderes / control (obliga Guardar asistente antes de cambiar)
    public bool $confirmSaveRequired = false;
    public bool $dirtyGrupo = false;   // cambios en representación (add/remove/separar)
    public bool $dirtyControl = false; // control preparado en cola

    // ---- Control ----
    public ?string $controlNumeroInput = null;
    public ?string $controlMsg = null;
    public ?string $controlError = null;

    // UI: preview mientras digita (sin asignar todavía)
    public ?string $controlPreviewSerial = null;
    public ?string $controlPreviewEstado = null;

    // ✅ Control "en cola" (se asigna realmente solo al Guardar asistente)
    public ?int $controlPendienteNumero = null;
    public ?int $controlPendienteId = null;

    // Modal elegante de control
    public bool $showControlModal = false;
    public ?string $controlModalTitle = null;
    public ?string $controlModalBody = null;

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

    // ---- Modo nominal ----
    public string $tipoQuorum = 'coeficiente';
    public ?int $personaId = null;
    public ?string $personaCedula = null;

    /**
     * ✅ Sincroniza el eventoId y tipoQuorum con el contexto del puesto.
     * Esto asegura aislamiento total: este componente opera SOLO dentro del evento activo.
     */
    private function syncEventoFromContext(): void
    {
        $ctx = app(EventContext::class);
        $this->eventoId   = $ctx->eventoId();
        $this->tipoQuorum = $ctx->tipoQuorum();
    }

    /**
     * Primera carga del componente
     */
    public function mount(): void
    {
        $this->syncEventoFromContext();

        // Extra seguridad (aunque el middleware ya bloquea)
        if (!$this->eventoId) {
            session()->flash('no_evento_activo', true);
            redirect()->route('eventos.index')->send();
        }
    }

    /**
     * Cada request AJAX de Livewire (evita quedar pegado al evento anterior)
     */
    public function hydrate(): void
    {
        $this->syncEventoFromContext();
    }

    public function updatedSearch(): void
    {
        if (!$this->eventoId) {
            $this->results = [];
            return;
        }

        $term = trim($this->search);

        if (mb_strlen($term) < 2) {
            $this->results = [];
            return;
        }

        if ($this->tipoQuorum === 'nominal') {
            $rows = DB::table('evento_personas')
                ->select('id', 'cedula', 'nombre')
                ->where('evento_id', $this->eventoId)
                ->where(function ($q) use ($term) {
                    $q->where('cedula', 'like', "%{$term}%")
                      ->orWhere('nombre', 'like', "%{$term}%");
                })
                ->limit(10)
                ->get();

            $this->results = $rows->map(fn($r) => [
                'id'    => (int) $r->id,
                'label' => "{$r->nombre} ({$r->cedula})",
            ])->toArray();
        } else {
            $rows = DB::table('evento_padron')
                ->select('id', 'inmueble', 'propietario')
                ->where('evento_id', $this->eventoId)
                ->where(function ($q) use ($term) {
                    $q->where('inmueble', 'like', "%{$term}%")
                      ->orWhere('propietario', 'like', "%{$term}%");
                })
                ->limit(10)
                ->get();

            $this->results = $rows->map(fn($r) => [
                'id'       => (int) $r->id,
                'label'    => (string) $r->inmueble,
                'sublabel' => (string) $r->propietario,
            ])->toArray();
        }
    }

    /**
     * ✅ selección protegida desde las sugerencias (coeficiente)
     */
    public function requestSelectInmueble(int $inmuebleId): void
    {
        if ($this->registroId && $this->hasPendingChanges()) {
            $this->confirmSaveRequired = true;
            $this->pendingAction = 'select';
            $this->pendingInmuebleId = $inmuebleId;
            return;
        }

        $this->selectInmueble($inmuebleId);
    }

    /**
     * Selección protegida desde las sugerencias (nominal)
     */
    public function requestSelectPersona(int $personaId): void
    {
        if ($this->registroId && $this->hasPendingChanges()) {
            $this->confirmSaveRequired = true;
            $this->pendingAction = 'select';
            $this->pendingInmuebleId = $personaId;
            return;
        }

        $this->selectPersona($personaId);
    }

    public function selectPersona(int $personaId): void
    {
        if (!$this->eventoId) {
            $this->checkinError = 'No hay evento activo en este puesto.';
            return;
        }

        $this->resetControlUi(true);
        $this->resetPoderUi();

        // Resolver: si es poder en otro grupo, abrir la cabeza
        [$targetId, $msg] = $this->resolveCheckinTargetNominal($personaId);
        $this->checkinMsg   = $msg;
        $this->checkinError = null;

        $personaId        = (int) $targetId;
        $this->personaId  = $personaId;

        $persona = DB::table('evento_personas')
            ->where('evento_id', $this->eventoId)
            ->where('id', $personaId)
            ->first();

        $this->inmuebleLabel   = $persona ? "{$persona->nombre} ({$persona->cedula})" : "Persona #{$personaId}";
        $this->propietarioLabel = $persona?->nombre ?? null;
        $this->personaCedula   = $persona?->cedula ?? null;
        $this->coefInmueble    = null;

        // Registro checkin
        $registro = DB::table('registros_checkin')
            ->where('evento_id', $this->eventoId)
            ->where('persona_id', $personaId)
            ->first();

        if (!$registro) {
            $newId = DB::table('registros_checkin')->insertGetId([
                'evento_id'        => $this->eventoId,
                'grupo_id'         => null,
                'inmueble_base_id' => null,
                'persona_id'       => $personaId,
                'estado'           => 'EN_PROCESO',
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);
            $registro = DB::table('registros_checkin')->where('id', $newId)->first();
        }

        $this->registroId    = (int) $registro->id;
        $this->inmuebleBaseId = null;
        $this->estado        = (string) $registro->estado;

        // Asistente (precargar desde evento_personas si no existe en el registro)
        $this->asistenteNombre   = $registro->asistente_nombre   ?? ($persona?->nombre   ?? null);
        $this->asistenteTelefono = $registro->asistente_telefono ?? ($persona?->telefono ?? null);
        $this->asistenteCorreo   = $registro->asistente_correo   ?? ($persona?->correo   ?? null);
        $this->errorTelefono     = null;

        $this->asistenteNombreOriginal   = $this->asistenteNombre;
        $this->asistenteTelefonoOriginal = $this->asistenteTelefono;
        $this->asistenteCorreoOriginal   = $this->asistenteCorreo;

        $this->confirmDiscard    = false;
        $this->pendingAction     = null;
        $this->pendingInmuebleId = null;

        // Control asignado (si existe)
        $this->controlNumero = null;
        $this->controlSerial = null;

        if (!is_null($registro->control_id)) {
            $control = DB::table('controles')
                ->select('numero', 'serial')
                ->where('id', $registro->control_id)
                ->first();

            $this->controlNumero = $control?->numero ?? null;
            $this->controlSerial = $control?->serial ?? null;
        }

        // Grupo nominal
        $this->ensureGrupoNominalForCabeza($personaId);

        $this->search  = '';
        $this->results = [];
    }

    public function selectInmueble(int $inmuebleId): void
    {
        // ✅ Seguridad: si no hay evento activo, no hacemos nada
        if (!$this->eventoId) {
            $this->checkinError = 'No hay evento activo en este puesto.';
            return;
        }

        // ✅ reset UI de mini-componentes para no dejar "rastros"
        $this->resetControlUi(true);
        $this->resetPoderUi();

        // ✅ Resolver antes de crear/usar registros_checkin:
        // si el inmueble buscado es PODER, abrimos la cabeza del grupo.
        [$targetId, $msg] = $this->resolveCheckinTarget($inmuebleId);

        $this->requestedPadronId = (int) $targetId;

        // mensajes (solo si aplica)
        $this->checkinMsg = $msg;
        $this->checkinError = null;

        $inmuebleId = (int) $targetId;

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
        $this->propietarioLabel = $padron?->propietario ?? null;
        $this->coefInmueble = $padron?->coeficiente !== null ? (float) $padron->coeficiente : null;


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

        // 4) Control asignado (si existe) -> cargar número y serial, y bloquear reasignación
        $this->controlNumero = null;
        $this->controlSerial = null;

        if (!is_null($registro->control_id)) {
            $control = DB::table('controles')
                ->select('numero', 'serial')
                ->where('id', $registro->control_id)
                ->first();

            $this->controlNumero = $control?->numero ?? null;
            $this->controlSerial = $control?->serial ?? null;
        }

        // 5) ✅ Representación: asegurar grupo real para ESTE inmueble (sin coronar cabezas indebidas)
        $this->ensureGrupoForCabeza($this->inmuebleBaseId);

        // 6) Limpiar búsqueda principal
        $this->search = '';
        $this->results = [];
    }

    private function resolveCheckinTarget(int $requestedPadronId): array
    {
        if (!$this->eventoId) {
            return [$requestedPadronId, null];
        }

        // Retorna: [targetPadronId, msg|null]
        $miembro = DB::table('representacion_miembros as rm')
            ->join('representacion_grupos as rg', 'rg.id', '=', 'rm.grupo_id')
            ->where('rm.evento_id', $this->eventoId)
            ->where('rg.evento_id', $this->eventoId)
            ->where('rm.padron_id', $requestedPadronId)
            ->select('rm.grupo_id', 'rg.cabeza_padron_id')
            ->first();

        if (!$miembro) {
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

    private function resolveCheckinTargetNominal(int $requestedPersonaId): array
    {
        if (!$this->eventoId) {
            return [$requestedPersonaId, null];
        }

        $miembro = DB::table('representacion_miembros_nominal as rm')
            ->join('representacion_grupos_nominal as rg', 'rg.id', '=', 'rm.grupo_id')
            ->where('rm.evento_id', $this->eventoId)
            ->where('rg.evento_id', $this->eventoId)
            ->where('rm.persona_id', $requestedPersonaId)
            ->select('rm.grupo_id', 'rg.cabeza_persona_id')
            ->first();

        if (!$miembro) {
            return [$requestedPersonaId, null];
        }

        $headId = (int) $miembro->cabeza_persona_id;

        if ($headId > 0 && $headId !== $requestedPersonaId) {
            $reqLabel  = $this->labelPersona($requestedPersonaId);
            $headLabel = $this->labelPersona($headId);
            return [$headId, "ℹ️ La persona {$reqLabel} está representada por {$headLabel}. Se abrirá la cabeza automáticamente."];
        }

        return [$requestedPersonaId, null];
    }

    private function ensureGrupoNominalForCabeza(int $personaId): void
    {
        if (!$this->eventoId) {
            $this->grupoId = null;
            $this->miembros = [];
            $this->coefTotal = null;
            $this->poderCount = null;
            $this->isCabezaSeleccionada = false;
            return;
        }

        $grupo = DB::table('representacion_grupos_nominal')
            ->where('evento_id', $this->eventoId)
            ->where('cabeza_persona_id', $personaId)
            ->first();

        if (!$grupo) {
            // Defensive: debería existir por import, pero creamos si falta
            $gid = DB::table('representacion_grupos_nominal')->insertGetId([
                'evento_id'         => $this->eventoId,
                'cabeza_persona_id' => $personaId,
                'created_at'        => now(),
                'updated_at'        => now(),
            ]);

            $existing = DB::table('representacion_miembros_nominal')
                ->where('evento_id', $this->eventoId)
                ->where('persona_id', $personaId)
                ->first();

            if (!$existing) {
                DB::table('representacion_miembros_nominal')->insert([
                    'evento_id'  => $this->eventoId,
                    'grupo_id'   => $gid,
                    'persona_id' => $personaId,
                    'es_cabeza'  => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('representacion_miembros_nominal')
                    ->where('id', $existing->id)
                    ->update(['grupo_id' => $gid, 'es_cabeza' => 1, 'updated_at' => now()]);
            }

            $this->grupoId = $gid;
        } else {
            $this->grupoId = (int) $grupo->id;
        }

        $this->isCabezaSeleccionada = true;

        $this->loadMiembrosNominal();
    }

    private function ensureGrupoForCabeza(int $padronId): void
    {
        if (!$this->eventoId) {
            $this->grupoId = null;
            $this->miembros = [];
            $this->coefTotal = null;
            $this->poderCount = null;
            $this->isCabezaSeleccionada = false;
            return;
        }

        $headPadronId = null;

        $miembroGlobal = DB::table('representacion_miembros')
            ->where('evento_id', $this->eventoId)
            ->where('padron_id', $padronId)
            ->first();

        if ($miembroGlobal) {
            $this->grupoId = (int) $miembroGlobal->grupo_id;

            $grupo = DB::table('representacion_grupos')
                ->where('id', $this->grupoId)
                ->where('evento_id', $this->eventoId)
                ->first();

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

                $grupo = DB::table('representacion_grupos')
                    ->where('id', $gid)
                    ->where('evento_id', $this->eventoId)
                    ->first();
            }

            $headPadronId = (int) ($grupo->cabeza_padron_id ?? 0);

            if ($headPadronId <= 0) {
                DB::table('representacion_grupos')
                    ->where('id', $this->grupoId)
                    ->where('evento_id', $this->eventoId)
                    ->update([
                        'cabeza_padron_id' => $padronId,
                        'updated_at' => now(),
                    ]);

                $headPadronId = $padronId;
            }

            $headMember = DB::table('representacion_miembros')
                ->where('evento_id', $this->eventoId)
                ->where('grupo_id', $this->grupoId)
                ->where('padron_id', $headPadronId)
                ->first();

            if (!$headMember) {
                DB::table('representacion_miembros')->insert([
                    'evento_id' => $this->eventoId,
                    'grupo_id' => $this->grupoId,
                    'padron_id' => $headPadronId,
                    'es_cabeza' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            DB::table('representacion_miembros')
                ->where('evento_id', $this->eventoId)
                ->where('grupo_id', $this->grupoId)
                ->where('padron_id', '!=', $headPadronId)
                ->update(['es_cabeza' => 0, 'updated_at' => now()]);

        } else {
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
                    'evento_id' => $this->eventoId,
                    'grupo_id' => $gid,
                    'padron_id' => $padronId,
                    'es_cabeza' => 1,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $this->grupoId = (int) $gid;
                $headPadronId = $padronId;
            } else {
                $this->grupoId = (int) $grupo->id;
                $headPadronId = (int) ($grupo->cabeza_padron_id ?? $padronId);

                $head = DB::table('representacion_miembros')
                    ->where('evento_id', $this->eventoId)
                    ->where('grupo_id', $this->grupoId)
                    ->where('padron_id', $headPadronId)
                    ->first();

                if (!$head) {
                    DB::table('representacion_miembros')->insert([
                        'evento_id' => $this->eventoId,
                        'grupo_id' => $this->grupoId,
                        'padron_id' => $headPadronId,
                        'es_cabeza' => 1,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        // ✅ reflejar grupo en el registro_checkin actual
        if ($this->registroId && $this->grupoId) {
            DB::table('registros_checkin')
                ->where('id', $this->registroId)
                ->where('evento_id', $this->eventoId)
                ->update(['grupo_id' => $this->grupoId]);
        }

        $this->isCabezaSeleccionada = ((int) $padronId === (int) $headPadronId);

        $this->loadMiembros();
    }

    private function loadMiembros(): void
    {
        if (!$this->eventoId || !$this->grupoId) {
            $this->miembros = [];
            $this->coefTotal = null;
            $this->poderCount = null;
            return;
        }

        $rows = DB::table('representacion_miembros as rm')
            ->join('evento_padron as ep', 'ep.id', '=', 'rm.padron_id')
            ->where('rm.evento_id', $this->eventoId)
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

    private function loadMiembrosNominal(): void
    {
        if (!$this->eventoId || !$this->grupoId) {
            $this->miembros   = [];
            $this->coefTotal  = null;
            $this->poderCount = null;
            return;
        }

        $rows = DB::table('representacion_miembros_nominal as rm')
            ->join('evento_personas as ep', 'ep.id', '=', 'rm.persona_id')
            ->where('rm.evento_id', $this->eventoId)
            ->where('rm.grupo_id', $this->grupoId)
            ->select(
                'rm.id as miembro_id',
                'rm.persona_id',
                'rm.es_cabeza',
                'ep.cedula',
                'ep.nombre'
            )
            ->orderByDesc('rm.es_cabeza')
            ->orderBy('ep.nombre')
            ->get();

        // Reutiliza las mismas claves de coeficiente para compatibilidad con la vista
        $this->miembros = $rows->map(fn($r) => [
            'miembro_id'  => (int) $r->miembro_id,
            'padron_id'   => (int) $r->persona_id,
            'persona_id'  => (int) $r->persona_id,
            'es_cabeza'   => (int) $r->es_cabeza === 1,
            'inmueble'    => (string) $r->cedula,
            'propietario' => (string) $r->nombre,
            'coeficiente' => 1.0,
        ])->toArray();

        $this->coefTotal  = (float) count($this->miembros);
        $this->poderCount = count(array_filter($this->miembros, fn($m) => !$m['es_cabeza']));
    }

    private function labelPersona(int $personaId): string
    {
        if (!$this->eventoId) {
            return (string) $personaId;
        }

        $p = DB::table('evento_personas')
            ->select('cedula', 'nombre')
            ->where('evento_id', $this->eventoId)
            ->where('id', $personaId)
            ->first();

        return $p ? "{$p->nombre} ({$p->cedula})" : (string) $personaId;
    }

    public function updatedPoderSearch(): void
    {
        $term = trim($this->poderSearch);

        if (!$this->eventoId || !$this->registroId || !$this->grupoId) {
            $this->poderResults = [];
            return;
        }

        if (mb_strlen($term) < 2) {
            $this->poderResults = [];
            return;
        }

        if ($this->tipoQuorum === 'nominal') {
            $rows = DB::table('evento_personas')
                ->select('id', 'cedula', 'nombre')
                ->where('evento_id', $this->eventoId)
                ->where(function ($q) use ($term) {
                    $q->where('cedula', 'like', "%{$term}%")
                      ->orWhere('nombre', 'like', "%{$term}%");
                })
                ->limit(8)
                ->get();

            $this->poderResults = $rows->map(fn($r) => [
                'id'          => (int) $r->id,
                'label'       => (string) $r->nombre,
                'propietario' => (string) $r->cedula,
                'coef'        => 1.0,
            ])->toArray();
        } else {
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
                'id'          => (int) $r->id,
                'label'       => (string) $r->inmueble,
                'propietario' => (string) $r->propietario,
                'coef'        => (float) $r->coeficiente,
            ])->toArray();
        }
    }

    /**
     * Preview del control mientras digita (NO asigna)
     */
    public function updatedControlNumeroInput(): void
    {
        $this->controlPreviewSerial = null;
        $this->controlPreviewEstado = null;
        $this->controlError = null;
        $this->controlMsg = null;

        $raw = trim((string) $this->controlNumeroInput);

        if (!$this->eventoId || $raw === '' || !ctype_digit($raw)) {
            return;
        }

        $num = (int) $raw;
        if ($num <= 0) {
            return;
        }

        $c = DB::table('controles')
            ->where('evento_id', $this->eventoId)
            ->where('numero', $num)
            ->first(['serial', 'estado']);

        if ($c) {
            $this->controlPreviewSerial = (string) $c->serial;
            $this->controlPreviewEstado = (string) $c->estado; // LIBRE / ASIGNADO
        }
    }

    /**
     * Confirmar con TAB/Enter/Blur (asigna SOLO si se puede)
     */
    public function confirmControl(): void
    {
        $this->controlMsg = null;
        $this->controlError = null;

        // Limpia "pendiente" cada vez que intentan confirmar
        $this->controlPendienteNumero = null;
        $this->controlPendienteId = null;

        if (!$this->eventoId) {
            $this->openControlModal('Sin evento activo', 'No hay evento activo en este puesto.');
            return;
        }

        if (!$this->registroId) {
            $this->openControlModal('Selecciona un inmueble', 'Primero selecciona un inmueble.');
            return;
        }

        if (!$this->isCabezaSeleccionada) {
            $this->openControlModal('Control bloqueado', 'Este inmueble es un PODER. El control solo se asigna desde la cabeza del grupo.');
            return;
        }

        // Si ya tiene control asignado, no permitir “preparar” otro
        $actual = DB::table('registros_checkin')
            ->where('evento_id', $this->eventoId)
            ->where('id', $this->registroId)
            ->first(['control_id']);

        if ($actual && !is_null($actual->control_id)) {
            $this->openControlModal('Ya tiene control', 'Este inmueble ya tiene un control asignado. No se puede preparar otro.');
            $this->controlNumeroInput = null;
            $this->controlPreviewSerial = null;
            $this->controlPreviewEstado = null;
            return;
        }

        $raw = trim((string) $this->controlNumeroInput);

        if ($raw === '' || !ctype_digit($raw) || (int) $raw <= 0) {
            $this->controlError = 'Ingresa un número de control válido.';
            return;
        }

        $num = (int) $raw;

        // Buscar control
        $c = DB::table('controles')
            ->where('evento_id', $this->eventoId)
            ->where('numero', $num)
            ->first(['id', 'serial', 'estado', 'asignado_a_registro_id']);

        if (!$c) {
            $this->openControlModal('No existe', "El control #{$num} no existe para este evento.");
            $this->controlNumeroInput = null;
            $this->controlPreviewSerial = null;
            $this->controlPreviewEstado = null;
            return;
        }

        // Mostrar preview siempre
        $this->controlPreviewSerial = (string) $c->serial;
        $this->controlPreviewEstado = (string) $c->estado;

        // Si no está libre -> modal con detalle
        if (strtoupper((string) $c->estado) !== 'LIBRE') {

            $msg = "El control #{$num} ({$c->serial}) ya está ASIGNADO.";

            if (!empty($c->asignado_a_registro_id)) {
                $reg = DB::table('registros_checkin')
                    ->where('evento_id', $this->eventoId)
                    ->where('id', (int) $c->asignado_a_registro_id)
                    ->first(['inmueble_base_id', 'persona_id']);

                if ($reg) {
                    if ($this->tipoQuorum === 'nominal' && !is_null($reg->persona_id)) {
                        $inm = $this->labelPersona((int) $reg->persona_id);
                    } else {
                        $inm = $this->labelInmueble((int) $reg->inmueble_base_id);
                    }
                    $msg .= " Actualmente está asignado a: {$inm}.";
                }
            }

            $this->openControlModal('Control ocupado', $msg);

            // Limpieza para reintento
            $this->controlNumeroInput = null;
            $this->controlPendienteNumero = null;
            $this->controlPendienteId = null;
            return;
        }

        // ✅ Está libre: quedará "listo para asignar" (pero NO se asigna aún)
        $this->controlPendienteNumero = $num;
        $this->controlPendienteId = (int) $c->id;

        $this->controlMsg = "✅ Control #{$num} ({$c->serial}) listo para asignar. Finaliza con Guardar asistente.";
        $this->dirtyControl = true;
    }

    private function openControlModal(string $title, string $body): void
    {
        $this->showControlModal = true;
        $this->controlModalTitle = $title;
        $this->controlModalBody = $body;
    }

    public function closeControlModal(): void
    {
        $this->showControlModal = false;
        $this->controlModalTitle = null;
        $this->controlModalBody = null;

        // reintento limpio
        $this->controlNumeroInput = null;
        $this->controlPreviewSerial = null;
        $this->controlPreviewEstado = null;

        // ✅ limpiar también el pendiente
        $this->controlPendienteNumero = null;
        $this->controlPendienteId = null;
    }

    private function labelInmueble(int $padronId): string
    {
        if (!$this->eventoId) {
            return (string) $padronId;
        }

        $p = DB::table('evento_padron')
            ->select('inmueble')
            ->where('evento_id', $this->eventoId)
            ->where('id', $padronId)
            ->first();

        return $p?->inmueble ?? (string) $padronId;
    }

    /**
     * ✅ Recalcula el snapshot del grupo actual si la cabeza ya tiene registro válido.
     * Esto evita desfases cuando agregan/quitan poderes después del check-in.
     */
    private function syncGrupoSnapshot(int $grupoId): void
    {
        if (!$this->eventoId || $grupoId <= 0) {
            return;
        }

        $grupo = DB::table('representacion_grupos')
            ->where('evento_id', $this->eventoId)
            ->where('id', $grupoId)
            ->first(['id', 'cabeza_padron_id']);

        if (!$grupo || empty($grupo->cabeza_padron_id)) {
            return;
        }

        $headPadronId = (int) $grupo->cabeza_padron_id;

        $head = DB::table('evento_padron')
            ->where('evento_id', $this->eventoId)
            ->where('id', $headPadronId)
            ->first(['inmueble']);

        if (!$head) {
            return;
        }

        $miembros = DB::table('representacion_miembros as rm')
            ->join('evento_padron as ep', 'ep.id', '=', 'rm.padron_id')
            ->where('rm.evento_id', $this->eventoId)
            ->where('rm.grupo_id', $grupoId)
            ->get(['ep.coeficiente']);

        $coefTotal = 0.0;
        foreach ($miembros as $m) {
            $coefTotal += (float) ($m->coeficiente ?? 0);
        }

        DB::table('registros_checkin')
            ->where('evento_id', $this->eventoId)
            ->where('inmueble_base_id', $headPadronId)
            ->whereIn('estado', ['CHECKED_IN', 'RETIRADO'])
            ->update([
                'grupo_id' => $grupoId,
                'coef_total_snapshot' => $coefTotal,
                'cabeza_inmueble_snapshot' => (string) $head->inmueble,
                'updated_at' => now(),
            ]);
    }

    private function syncGrupoNominalSnapshot(int $grupoNominalId): void
    {
        if (!$this->eventoId || $grupoNominalId <= 0) {
            return;
        }

        $grupo = DB::table('representacion_grupos_nominal')
            ->where('evento_id', $this->eventoId)
            ->where('id', $grupoNominalId)
            ->first(['id', 'cabeza_persona_id']);

        if (!$grupo || empty($grupo->cabeza_persona_id)) {
            return;
        }

        $headPersonaId = (int) $grupo->cabeza_persona_id;

        $persona = DB::table('evento_personas')
            ->where('evento_id', $this->eventoId)
            ->where('id', $headPersonaId)
            ->first(['nombre']);

        if (!$persona) {
            return;
        }

        $count = (int) DB::table('representacion_miembros_nominal')
            ->where('evento_id', $this->eventoId)
            ->where('grupo_id', $grupoNominalId)
            ->count();

        DB::table('registros_checkin')
            ->where('evento_id', $this->eventoId)
            ->where('persona_id', $headPersonaId)
            ->whereIn('estado', ['CHECKED_IN', 'RETIRADO'])
            ->update([
                'coef_total_snapshot'     => (float) $count,
                'cabeza_inmueble_snapshot' => (string) $persona->nombre,
                'updated_at'              => now(),
            ]);
    }

    public function addPoder(int $padronId): void
    {
        $this->poderError = null;
        $this->poderMsg = null;

        if (!$this->eventoId || !$this->grupoId) {
            $this->poderError = 'Primero selecciona un inmueble.';
            return;
        }

        // ✅ No puede agregarse a sí mismo
        if ((int) $padronId === (int) $this->inmuebleBaseId) {
            $this->poderError = 'Ese inmueble ya es la cabeza del grupo.';
            return;
        }

        $targetGrupoId = (int) $this->grupoId;

        $result = DB::transaction(function () use ($padronId) {

            // ✅ BLOQUEO DURO (con lock) contra carreras entre pantallas:
            // si ese inmueble ya está CHECKED_IN o tiene control asignado, NO se puede anexar
            $reg = DB::table('registros_checkin')
                ->where('evento_id', $this->eventoId)
                ->where('inmueble_base_id', $padronId)
                ->lockForUpdate()
                ->first(['estado', 'control_id', 'checked_in_at']);

            if ($reg) {
                $estado = strtoupper((string) ($reg->estado ?? ''));
                $tieneControl = !is_null($reg->control_id);

                if ($estado === 'CHECKED_IN' || $tieneControl) {
                    return [
                        'status' => 'locked_checkedin_or_control',
                        'estado' => $estado,
                        'tiene_control' => $tieneControl,
                    ];
                }
            }

            // 1) Lock del miembro (si existe)
            $exists = DB::table('representacion_miembros')
                ->where('evento_id', $this->eventoId)
                ->where('padron_id', $padronId)
                ->lockForUpdate()
                ->first();

            // 2) Si no existe: insertarlo como poder
            if (!$exists) {
                DB::table('representacion_miembros')->insert([
                    'evento_id' => $this->eventoId,
                    'grupo_id' => $this->grupoId,
                    'padron_id' => $padronId,
                    'es_cabeza' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                return ['status' => 'inserted'];
            }

            // 3) Si ya está en este grupo: ok informativo
            if ((int) $exists->grupo_id === (int) $this->grupoId) {
                return ['status' => 'already_in_group'];
            }

            // 4) Está en otro grupo: decidir si se puede mover o bloquear informando cabeza
            $otherGroupId = (int) $exists->grupo_id;

            $otherGroup = DB::table('representacion_grupos')
                ->where('evento_id', $this->eventoId)
                ->where('id', $otherGroupId)
                ->lockForUpdate()
                ->first(['id', 'cabeza_padron_id']);

            $headPadronId = (int) ($otherGroup->cabeza_padron_id ?? 0);

            // Si no existe el grupo o no hay cabeza definida, bloqueamos (inconsistencia)
            if (!$otherGroup || $headPadronId <= 0) {
                return [
                    'status' => 'blocked_in_other_group',
                    'head_padron_id' => null,
                ];
            }

            // ✅ Caso A: está en su grupo base (cabeza = él mismo)
            if ($headPadronId === (int) $padronId) {

                $cnt = (int) DB::table('representacion_miembros')
                    ->where('evento_id', $this->eventoId)
                    ->where('grupo_id', $otherGroupId)
                    ->lockForUpdate()
                    ->count();

                // solo si está SOLO, lo dejamos mover como poder al grupo actual
                if ($cnt === 1) {
                    DB::table('representacion_miembros')
                        ->where('evento_id', $this->eventoId)
                        ->where('id', $exists->id)
                        ->update([
                            'grupo_id' => $this->grupoId,
                            'es_cabeza' => 0,
                            'updated_at' => now(),
                        ]);

                    return ['status' => 'moved_from_base'];
                }

                // no está solo en su base -> bloqueamos e informamos cabeza (él mismo)
                return [
                    'status' => 'blocked_in_other_group',
                    'head_padron_id' => $headPadronId,
                ];
            }

            // ✅ Caso B: pertenece a un grupo cuya cabeza es OTRO inmueble -> bloqueamos e informamos cuál
            return [
                'status' => 'blocked_in_other_group',
                'head_padron_id' => $headPadronId,
            ];
        });

        // ✅ Manejo de respuesta post-transacción
        if (($result['status'] ?? null) === 'locked_checkedin_or_control') {
            $label = $this->labelInmueble($padronId);

            $detalle = [];
            if (($result['estado'] ?? '') === 'CHECKED_IN')
                $detalle[] = 'ya está CHECKED_IN';
            if (!empty($result['tiene_control']))
                $detalle[] = 'ya tiene control asignado';

            $this->openControlModal(
                'No se puede anexar',
                "El inmueble {$label} no se puede anexar como poder porque " . implode(' y ', $detalle) . "."
            );

            $this->poderSearch = '';
            $this->poderResults = [];
            return;
        }

        if (($result['status'] ?? null) === 'blocked_in_other_group') {
            $label = $this->labelInmueble($padronId);

            $headId = $result['head_padron_id'] ?? null;
            $headLabel = $headId ? $this->labelInmueble((int) $headId) : 'otro inmueble';

            $this->openControlModal(
                'No se puede anexar',
                "El inmueble {$label} ya está asignado a un grupo cuya cabeza es {$headLabel}. Debes abrir la cabeza para gestionarlo desde allá."
            );

            $this->poderSearch = '';
            $this->poderResults = [];
            return;
        }

        if (in_array(($result['status'] ?? null), ['inserted', 'moved_from_base'], true)) {
            $this->syncGrupoSnapshot($targetGrupoId);
            $this->poderMsg = '✅ Poder anexado correctamente.';
        } elseif (($result['status'] ?? null) === 'already_in_group') {
            $this->poderMsg = 'ℹ️ Ese inmueble ya pertenece a este grupo.';
        } else {
            $this->poderError = 'No se pudo anexar el poder.';
        }

        if (in_array(($result['status'] ?? null), ['inserted', 'moved_from_base'], true)) {
            $this->dirtyGrupo = true;
        }

        $this->poderSearch = '';
        $this->poderResults = [];
        $this->loadMiembros();
    }

    public function removePoder(int $miembroId): void
    {
        $this->poderError = null;
        $this->poderMsg = null;

        if (!$this->eventoId) {
            $this->poderError = 'No hay evento activo en este puesto.';
            return;
        }

        if (!$this->grupoId) {
            $this->poderError = 'Primero selecciona un inmueble.';
            return;
        }

        $eventoId = (int) $this->eventoId;

        try {
            $out = DB::transaction(function () use ($miembroId, $eventoId) {

                // 1) Lock del miembro (del evento)
                $m = DB::table('representacion_miembros')
                    ->where('evento_id', $eventoId)
                    ->where('id', $miembroId)
                    ->lockForUpdate()
                    ->first();

                if (!$m) {
                    return ['ok' => false, 'msg' => 'No se encontró el poder.'];
                }

                if ((int) $m->es_cabeza === 1) {
                    return ['ok' => false, 'msg' => 'No puedes quitar la cabeza del grupo.'];
                }

                $padronId = (int) $m->padron_id;
                $grupoActualId = (int) $m->grupo_id;

                // 2) Buscar/lock registro_checkin del poder (si existe) y liberar control si lo tuviera
                $regPoder = DB::table('registros_checkin')
                    ->where('evento_id', $eventoId)
                    ->where('inmueble_base_id', $padronId)
                    ->lockForUpdate()
                    ->first();

                $freedControlNum = null;

                if ($regPoder && !is_null($regPoder->control_id)) {

                    // Lock del control
                    $control = DB::table('controles')
                        ->where('id', $regPoder->control_id)
                        ->lockForUpdate()
                        ->first();

                    if ($control) {
                        $freedControlNum = (int) $control->numero;

                        // Liberar control (queda disponible)
                        DB::table('controles')
                            ->where('id', $control->id)
                            ->update([
                                'estado' => 'LIBRE',
                                'asignado_a_registro_id' => null,
                                'updated_at' => now(),
                            ]);
                    }

                    // Limpiar control del registro del poder
                    DB::table('registros_checkin')
                        ->where('id', $regPoder->id)
                        ->update([
                            'control_id' => null,
                            'control_numero_snapshot' => null,
                            'updated_at' => now(),
                        ]);

                    // refrescar objeto (por coherencia en lo que sigue)
                    $regPoder = DB::table('registros_checkin')
                        ->where('id', $regPoder->id)
                        ->lockForUpdate()
                        ->first();
                }

                // 3) Asegurar grupo base para este padronId (cabeza = él)
                $grupoBase = DB::table('representacion_grupos')
                    ->where('evento_id', $eventoId)
                    ->where('cabeza_padron_id', $padronId)
                    ->lockForUpdate()
                    ->first();

                if ($grupoBase) {
                    $grupoBaseId = (int) $grupoBase->id;

                    $cnt = (int) DB::table('representacion_miembros')
                        ->where('evento_id', $eventoId)
                        ->where('grupo_id', $grupoBaseId)
                        ->lockForUpdate()
                        ->count();

                    if ($cnt > 1) {
                        return [
                            'ok' => false,
                            'msg' => 'No se pudo separar este poder porque ya es cabeza de otro grupo con poderes. Requiere validación/admin.',
                        ];
                    }
                } else {
                    $grupoBaseId = (int) DB::table('representacion_grupos')->insertGetId([
                        'evento_id' => $eventoId,
                        'cabeza_padron_id' => $padronId,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // 4) Mover este miembro al grupo base y convertirlo en cabeza
                DB::table('representacion_miembros')
                    ->where('evento_id', $eventoId)
                    ->where('id', $m->id)
                    ->update([
                        'grupo_id' => $grupoBaseId,
                        'es_cabeza' => 1,
                        'updated_at' => now(),
                    ]);

                // 5) Saneo: ese grupo base debe tener como cabeza_padron_id al padronId
                DB::table('representacion_grupos')
                    ->where('evento_id', $eventoId)
                    ->where('id', $grupoBaseId)
                    ->update([
                        'cabeza_padron_id' => $padronId,
                        'updated_at' => now(),
                    ]);

                // 6) ✅ Reset total del check-in del poder (queda “en cero”)
                $reset = [
                    'grupo_id' => $grupoBaseId,
                    'estado' => 'EN_PROCESO',

                    'asistente_nombre' => null,
                    'asistente_telefono' => null,
                    'asistente_correo' => null,

                    'control_id' => null,
                    'control_numero_snapshot' => null,

                    // ✅ deja el inmueble como “no chequeado”
                    'checked_in_at' => null,
                    'checked_in_by_user_id' => null,
                    'station_id' => null,

                    'updated_at' => now(),
                ];

                // ✅ si existe la columna control_serial_snapshot, también la limpiamos
                if (\Illuminate\Support\Facades\Schema::hasColumn('registros_checkin', 'control_serial_snapshot')) {
                    $reset['control_serial_snapshot'] = null;
                }

                if ($regPoder) {
                    DB::table('registros_checkin')
                        ->where('id', $regPoder->id)
                        ->where('evento_id', $eventoId)
                        ->update($reset);
                } else {
                    DB::table('registros_checkin')->insert(array_merge($reset, [
                        'evento_id' => $eventoId,
                        'inmueble_base_id' => $padronId,
                        'created_at' => now(),
                    ]));
                }

                return [
                    'ok' => true,
                    'padron_id' => $padronId,
                    'grupo_base_id' => $grupoBaseId,
                    'grupo_actual_id' => $grupoActualId,
                    'freed_control_num' => $freedControlNum,
                ];
            });

            if (empty($out['ok'])) {
                $this->poderError = $out['msg'] ?? 'No fue posible quitar el poder.';
                return;
            }

            $this->syncGrupoSnapshot((int) $out['grupo_actual_id']);

            $inm = $this->labelInmueble((int) $out['padron_id']);

            $extra = '';
            if (!empty($out['freed_control_num'])) {
                $extra = " (Se liberó el control #{$out['freed_control_num']})";
            }

            $this->poderMsg = "🗑️ Poder removido: {$inm}. Quedó independiente y con check-in en 0{$extra}.";
            $this->dirtyGrupo = true;
            $this->loadMiembros();

        } catch (\Throwable $e) {
            $this->poderError = app()->isLocal()
                ? 'Error quitando poder: ' . $e->getMessage()
                : 'Ocurrió un error quitando el poder. Revisa logs.';
            return;
        }
    }

    public function addPoderNominal(int $personaId): void
    {
        $this->poderError = null;
        $this->poderMsg   = null;

        if (!$this->eventoId || !$this->grupoId) {
            $this->poderError = 'Primero selecciona una persona.';
            return;
        }

        if ((int) $personaId === (int) $this->personaId) {
            $this->poderError = 'Esa persona ya es la cabeza del grupo.';
            return;
        }

        $targetGrupoId = (int) $this->grupoId;

        $result = DB::transaction(function () use ($personaId) {

            $reg = DB::table('registros_checkin')
                ->where('evento_id', $this->eventoId)
                ->where('persona_id', $personaId)
                ->lockForUpdate()
                ->first(['estado', 'control_id']);

            if ($reg) {
                $estado      = strtoupper((string) ($reg->estado ?? ''));
                $tieneControl = !is_null($reg->control_id);

                if ($estado === 'CHECKED_IN' || $tieneControl) {
                    return ['status' => 'locked_checkedin_or_control', 'estado' => $estado, 'tiene_control' => $tieneControl];
                }
            }

            $exists = DB::table('representacion_miembros_nominal')
                ->where('evento_id', $this->eventoId)
                ->where('persona_id', $personaId)
                ->lockForUpdate()
                ->first();

            if (!$exists) {
                DB::table('representacion_miembros_nominal')->insert([
                    'evento_id'  => $this->eventoId,
                    'grupo_id'   => $this->grupoId,
                    'persona_id' => $personaId,
                    'es_cabeza'  => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                return ['status' => 'inserted'];
            }

            if ((int) $exists->grupo_id === (int) $this->grupoId) {
                return ['status' => 'already_in_group'];
            }

            $otherGroupId = (int) $exists->grupo_id;

            $otherGroup = DB::table('representacion_grupos_nominal')
                ->where('evento_id', $this->eventoId)
                ->where('id', $otherGroupId)
                ->lockForUpdate()
                ->first(['id', 'cabeza_persona_id']);

            $headPersonaId = (int) ($otherGroup->cabeza_persona_id ?? 0);

            if (!$otherGroup || $headPersonaId <= 0) {
                return ['status' => 'blocked_in_other_group', 'head_persona_id' => null];
            }

            // Caso A: es la cabeza de su propio grupo y está sola → puede moverse
            if ($headPersonaId === (int) $personaId) {
                $cnt = (int) DB::table('representacion_miembros_nominal')
                    ->where('evento_id', $this->eventoId)
                    ->where('grupo_id', $otherGroupId)
                    ->lockForUpdate()
                    ->count();

                if ($cnt === 1) {
                    DB::table('representacion_miembros_nominal')
                        ->where('evento_id', $this->eventoId)
                        ->where('id', $exists->id)
                        ->update(['grupo_id' => $this->grupoId, 'es_cabeza' => 0, 'updated_at' => now()]);

                    return ['status' => 'moved_from_base'];
                }

                return ['status' => 'blocked_in_other_group', 'head_persona_id' => $headPersonaId];
            }

            // Caso B: pertenece a otro grupo cuya cabeza es otra persona
            return ['status' => 'blocked_in_other_group', 'head_persona_id' => $headPersonaId];
        });

        if (($result['status'] ?? null) === 'locked_checkedin_or_control') {
            $label = $this->labelPersona($personaId);
            $detalle = [];
            if (($result['estado'] ?? '') === 'CHECKED_IN') $detalle[] = 'ya está CHECKED_IN';
            if (!empty($result['tiene_control'])) $detalle[] = 'ya tiene control asignado';
            $this->openControlModal('No se puede anexar', "La persona {$label} no se puede anexar porque " . implode(' y ', $detalle) . ".");
            $this->poderSearch = '';
            $this->poderResults = [];
            return;
        }

        if (($result['status'] ?? null) === 'blocked_in_other_group') {
            $label    = $this->labelPersona($personaId);
            $headId   = $result['head_persona_id'] ?? null;
            $headLabel = $headId ? $this->labelPersona((int) $headId) : 'otra persona';
            $this->openControlModal('No se puede anexar', "La persona {$label} ya está asignada a un grupo cuya cabeza es {$headLabel}.");
            $this->poderSearch = '';
            $this->poderResults = [];
            return;
        }

        if (in_array(($result['status'] ?? null), ['inserted', 'moved_from_base'], true)) {
            $this->syncGrupoNominalSnapshot($targetGrupoId);
            $this->poderMsg  = '✅ Poder nominal anexado correctamente.';
            $this->dirtyGrupo = true;
        } elseif (($result['status'] ?? null) === 'already_in_group') {
            $this->poderMsg = 'ℹ️ Esa persona ya pertenece a este grupo.';
        } else {
            $this->poderError = 'No se pudo anexar el poder.';
        }

        $this->poderSearch  = '';
        $this->poderResults = [];
        $this->loadMiembrosNominal();
    }

    public function removePoderNominal(int $miembroId): void
    {
        $this->poderError = null;
        $this->poderMsg   = null;

        if (!$this->eventoId || !$this->grupoId) {
            $this->poderError = 'No hay evento activo en este puesto.';
            return;
        }

        $eventoId = (int) $this->eventoId;

        try {
            $out = DB::transaction(function () use ($miembroId, $eventoId) {

                $m = DB::table('representacion_miembros_nominal')
                    ->where('evento_id', $eventoId)
                    ->where('id', $miembroId)
                    ->lockForUpdate()
                    ->first();

                if (!$m) {
                    return ['ok' => false, 'msg' => 'No se encontró el poder.'];
                }

                if ((int) $m->es_cabeza === 1) {
                    return ['ok' => false, 'msg' => 'No puedes quitar la cabeza del grupo.'];
                }

                $personaId    = (int) $m->persona_id;
                $grupoActualId = (int) $m->grupo_id;

                // Liberar control si lo tuviera
                $regPoder = DB::table('registros_checkin')
                    ->where('evento_id', $eventoId)
                    ->where('persona_id', $personaId)
                    ->lockForUpdate()
                    ->first();

                $freedControlNum = null;

                if ($regPoder && !is_null($regPoder->control_id)) {
                    $control = DB::table('controles')->where('id', $regPoder->control_id)->lockForUpdate()->first();

                    if ($control) {
                        $freedControlNum = (int) $control->numero;
                        DB::table('controles')->where('id', $control->id)
                            ->update(['estado' => 'LIBRE', 'asignado_a_registro_id' => null, 'updated_at' => now()]);
                    }

                    DB::table('registros_checkin')->where('id', $regPoder->id)
                        ->update(['control_id' => null, 'control_numero_snapshot' => null, 'updated_at' => now()]);

                    $regPoder = DB::table('registros_checkin')->where('id', $regPoder->id)->lockForUpdate()->first();
                }

                // Asegurar grupo base para esta persona (cabeza = ella sola)
                $grupoBase = DB::table('representacion_grupos_nominal')
                    ->where('evento_id', $eventoId)
                    ->where('cabeza_persona_id', $personaId)
                    ->lockForUpdate()
                    ->first();

                if ($grupoBase) {
                    $grupoBaseId = (int) $grupoBase->id;
                    $cnt = (int) DB::table('representacion_miembros_nominal')
                        ->where('evento_id', $eventoId)
                        ->where('grupo_id', $grupoBaseId)
                        ->lockForUpdate()
                        ->count();

                    if ($cnt > 1) {
                        return ['ok' => false, 'msg' => 'No se pudo separar: la persona ya es cabeza de otro grupo con poderes.'];
                    }
                } else {
                    $grupoBaseId = (int) DB::table('representacion_grupos_nominal')->insertGetId([
                        'evento_id'         => $eventoId,
                        'cabeza_persona_id' => $personaId,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]);
                }

                // Mover al grupo base y convertir en cabeza
                DB::table('representacion_miembros_nominal')
                    ->where('evento_id', $eventoId)
                    ->where('id', $m->id)
                    ->update(['grupo_id' => $grupoBaseId, 'es_cabeza' => 1, 'updated_at' => now()]);

                DB::table('representacion_grupos_nominal')
                    ->where('evento_id', $eventoId)
                    ->where('id', $grupoBaseId)
                    ->update(['cabeza_persona_id' => $personaId, 'updated_at' => now()]);

                // Reset check-in del poder
                $reset = [
                    'grupo_id'            => null,
                    'estado'              => 'EN_PROCESO',
                    'asistente_nombre'    => null,
                    'asistente_telefono'  => null,
                    'asistente_correo'    => null,
                    'control_id'          => null,
                    'control_numero_snapshot' => null,
                    'checked_in_at'       => null,
                    'checked_in_by_user_id' => null,
                    'station_id'          => null,
                    'updated_at'          => now(),
                ];

                if (\Illuminate\Support\Facades\Schema::hasColumn('registros_checkin', 'control_serial_snapshot')) {
                    $reset['control_serial_snapshot'] = null;
                }

                if ($regPoder) {
                    DB::table('registros_checkin')
                        ->where('id', $regPoder->id)
                        ->where('evento_id', $eventoId)
                        ->update($reset);
                } else {
                    DB::table('registros_checkin')->insert(array_merge($reset, [
                        'evento_id'        => $eventoId,
                        'persona_id'       => $personaId,
                        'inmueble_base_id' => null,
                        'created_at'       => now(),
                    ]));
                }

                return [
                    'ok'              => true,
                    'persona_id'      => $personaId,
                    'grupo_base_id'   => $grupoBaseId,
                    'grupo_actual_id' => $grupoActualId,
                    'freed_control_num' => $freedControlNum,
                ];
            });

            if (empty($out['ok'])) {
                $this->poderError = $out['msg'] ?? 'No fue posible quitar el poder.';
                return;
            }

            $this->syncGrupoNominalSnapshot((int) $out['grupo_actual_id']);

            $label = $this->labelPersona((int) $out['persona_id']);
            $extra = '';
            if (!empty($out['freed_control_num'])) {
                $extra = " (Se liberó el control #{$out['freed_control_num']})";
            }

            $this->poderMsg   = "🗑️ Poder removido: {$label}. Quedó independiente{$extra}.";
            $this->dirtyGrupo = true;
            $this->loadMiembrosNominal();

        } catch (\Throwable $e) {
            $this->poderError = app()->isLocal()
                ? 'Error quitando poder: ' . $e->getMessage()
                : 'Ocurrió un error quitando el poder. Revisa logs.';
        }
    }

    public function separarCabeza(): void
    {
        $this->checkinMsg = null;
        $this->checkinError = null;

        if (!$this->eventoId || !$this->registroId || !$this->grupoId || !$this->inmuebleBaseId) {
            $this->checkinError = 'Primero selecciona un inmueble.';
            return;
        }

        if (property_exists($this, 'isCabezaSeleccionada') && !$this->isCabezaSeleccionada) {
            $this->checkinError = 'Solo puedes separar desde la cabeza del grupo.';
            return;
        }

        if (count($this->miembros) < 2) {
            $this->checkinError = 'Este grupo no tiene poderes. No hay a quién promover como nueva cabeza.';
            return;
        }

        $eventoId = (int) $this->eventoId;
        $grupoId = (int) $this->grupoId;

        $this->resetControlUi();
        $this->resetPoderUi();

        try {
            $out = DB::transaction(function () use ($eventoId, $grupoId) {

                $grupoActual = DB::table('representacion_grupos')
                    ->where('id', $grupoId)
                    ->where('evento_id', $eventoId)
                    ->lockForUpdate()
                    ->first();

                if (!$grupoActual) {
                    return ['ok' => false, 'msg' => 'No se encontró el grupo.'];
                }

                $oldHeadPadronId = (int) $grupoActual->cabeza_padron_id;

                $miembros = DB::table('representacion_miembros')
                    ->where('evento_id', $eventoId)
                    ->where('grupo_id', $grupoId)
                    ->lockForUpdate()
                    ->get();

                if ($miembros->count() < 2) {
                    return ['ok' => false, 'msg' => 'El grupo no tiene poderes para promover.'];
                }

                $nuevo = $miembros->firstWhere('es_cabeza', 0);

                if (!$nuevo) {
                    $nuevo = $miembros->firstWhere('padron_id', '!=', $oldHeadPadronId);
                }

                if (!$nuevo) {
                    return ['ok' => false, 'msg' => 'No fue posible determinar el nuevo cabeza.'];
                }

                $newHeadPadronId = (int) $nuevo->padron_id;

                $dupGrupo = DB::table('representacion_grupos')
                    ->where('evento_id', $eventoId)
                    ->where('cabeza_padron_id', $newHeadPadronId)
                    ->lockForUpdate()
                    ->first();

                if ($dupGrupo && (int) $dupGrupo->id !== (int) $grupoActual->id) {

                    $dupCount = (int) DB::table('representacion_miembros')
                        ->where('evento_id', $eventoId)
                        ->where('grupo_id', $dupGrupo->id)
                        ->lockForUpdate()
                        ->count();

                    if ($dupCount <= 1) {
                        DB::table('representacion_grupos')
                            ->where('id', $dupGrupo->id)
                            ->where('evento_id', $eventoId)
                            ->delete();
                    } else {
                        return [
                            'ok' => false,
                            'code' => 'candidate_has_other_group',
                            'msg' => 'No se pudo separar: el candidato a nueva cabeza ya es cabeza de otro grupo con poderes. Requiere validación/admin.',
                        ];
                    }
                }

                DB::table('representacion_grupos')
                    ->where('id', $grupoId)
                    ->where('evento_id', $eventoId)
                    ->update([
                        'cabeza_padron_id' => $newHeadPadronId,
                        'updated_at' => now(),
                    ]);

                DB::table('representacion_miembros')
                    ->where('evento_id', $eventoId)
                    ->where('grupo_id', $grupoId)
                    ->update(['es_cabeza' => 0, 'updated_at' => now()]);

                DB::table('representacion_miembros')
                    ->where('evento_id', $eventoId)
                    ->where('grupo_id', $grupoId)
                    ->where('padron_id', $newHeadPadronId)
                    ->update(['es_cabeza' => 1, 'updated_at' => now()]);

                $oldRegistro = DB::table('registros_checkin')
                    ->where('evento_id', $eventoId)
                    ->where('inmueble_base_id', $oldHeadPadronId)
                    ->lockForUpdate()
                    ->first();

                $controlNum = null;

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
                    DB::table('registros_checkin')
                        ->where('id', $newRegistro->id)
                        ->where('evento_id', $eventoId)
                        ->update(['grupo_id' => $grupoId, 'updated_at' => now()]);
                }

                if ($oldRegistro && !is_null($oldRegistro->control_id)) {

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

                        DB::table('registros_checkin')
                            ->where('id', $newRegistro->id)
                            ->where('evento_id', $eventoId)
                            ->update([
                                'control_id' => $control->id,
                                'control_numero_snapshot' => $control->numero,
                                'updated_at' => now(),
                            ]);

                        DB::table('controles')
                            ->where('id', $control->id)
                            ->update([
                                'estado' => 'ASIGNADO',
                                'asignado_a_registro_id' => $newRegistro->id,
                                'updated_at' => now(),
                            ]);

                        DB::table('registros_checkin')
                            ->where('id', $oldRegistro->id)
                            ->where('evento_id', $eventoId)
                            ->update([
                                'control_id' => null,
                                'control_numero_snapshot' => null,
                                'updated_at' => now(),
                            ]);
                    }
                }

                $oldDup = DB::table('representacion_grupos')
                    ->where('evento_id', $eventoId)
                    ->where('cabeza_padron_id', $oldHeadPadronId)
                    ->lockForUpdate()
                    ->first();

                if ($oldDup) {
                    $oldDupCount = (int) DB::table('representacion_miembros')
                        ->where('evento_id', $eventoId)
                        ->where('grupo_id', $oldDup->id)
                        ->lockForUpdate()
                        ->count();

                    if ($oldDupCount <= 1) {
                        DB::table('representacion_grupos')
                            ->where('id', $oldDup->id)
                            ->where('evento_id', $eventoId)
                            ->delete();
                    } else {
                        return [
                            'ok' => false,
                            'msg' => 'No se pudo separar: la cabeza actual ya existe como cabeza de otro grupo con poderes. Requiere validación/admin.',
                        ];
                    }
                }

                $newBaseGroupId = DB::table('representacion_grupos')->insertGetId([
                    'evento_id' => $eventoId,
                    'cabeza_padron_id' => $oldHeadPadronId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('representacion_miembros')
                    ->where('evento_id', $eventoId)
                    ->where('padron_id', $oldHeadPadronId)
                    ->update([
                        'grupo_id' => $newBaseGroupId,
                        'es_cabeza' => 1,
                        'updated_at' => now(),
                    ]);

                if ($oldRegistro) {
                    DB::table('registros_checkin')
                        ->where('id', $oldRegistro->id)
                        ->where('evento_id', $eventoId)
                        ->update(['grupo_id' => $newBaseGroupId, 'updated_at' => now()]);
                }

                return [
                    'ok' => true,
                    'old_head' => $oldHeadPadronId,
                    'new_head' => $newHeadPadronId,
                    'control_num' => $controlNum,
                    'grupo_id' => $grupoId,
                    'new_base_group_id' => $newBaseGroupId,
                ];
            });

            if (empty($out['ok'])) {
                $this->checkinError = $out['msg'] ?? 'No fue posible separar la cabeza.';
                return;
            }

            $this->syncGrupoSnapshot((int) $out['grupo_id']);
            $this->syncGrupoSnapshot((int) $out['new_base_group_id']);

            $old = $this->labelInmueble((int) $out['old_head']);
            $new = $this->labelInmueble((int) $out['new_head']);

            $extra = '';
            if (!empty($out['control_num'])) {
                $extra = " Control #{$out['control_num']} migrado al nuevo cabeza.";
            }

            $this->checkinMsg = "✅ Cabeza separada: {$old} ahora quedó independiente. Nuevo cabeza del grupo: {$new}.{$extra}";
            $this->dirtyGrupo = true;
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
        $this->checkinMsg = null;
        $this->checkinError = null;

        if (!$this->eventoId || !$this->registroId) {
            $this->checkinError = 'Primero selecciona un inmueble.';
            return;
        }

        // ✅ Teléfono obligatorio
        $tel = trim((string) $this->asistenteTelefono);
        if ($tel === '') {
            $this->errorTelefono = 'El teléfono es obligatorio para el registro.';
            return;
        }
        $this->errorTelefono = null;

        // ✅ Solo desde cabeza se “cierra” check-in (si es poder, no)
        if (!$this->isCabezaSeleccionada) {
            $this->checkinError = 'Este inmueble es un PODER. El check-in final se cierra únicamente desde la cabeza del grupo.';
            return;
        }

        DB::transaction(function () {

            // Lock del registro
            $reg = DB::table('registros_checkin')
                ->where('evento_id', $this->eventoId)
                ->where('id', $this->registroId)
                ->lockForUpdate()
                ->first();

            if (!$reg) {
                throw new \RuntimeException('No se encontró el registro.');
            }

            // Si NO tiene control aún, debe existir uno "pendiente"
            if (is_null($reg->control_id)) {

                if (!$this->controlPendienteNumero || !$this->controlPendienteId) {
                    // No hay control listo
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'control' => 'Debes preparar un control (Tab/Enter) antes de guardar el asistente.',
                    ]);
                }

                // Lock del control pendiente y re-validación dura
                $control = DB::table('controles')
                    ->where('evento_id', $this->eventoId)
                    ->where('id', $this->controlPendienteId)
                    ->lockForUpdate()
                    ->first();

                if (!$control) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'control' => 'El control preparado ya no existe. Intenta nuevamente.',
                    ]);
                }

                if (strtoupper((string) $control->estado) !== 'LIBRE') {
                    $msg = "El control #{$control->numero} ({$control->serial}) ya no está libre.";
                    throw \Illuminate\Validation\ValidationException::withMessages(['control' => $msg]);
                }

                // ✅ Asignación real con tu servicio (reglas centralizadas)
                app(ControlService::class)->assignByNumero($this->eventoId, $this->registroId, (int) $this->controlPendienteNumero);
            }

            // Refrescar control asignado para pintar header bien
            $reg2 = DB::table('registros_checkin')
                ->where('evento_id', $this->eventoId)
                ->where('id', $this->registroId)
                ->lockForUpdate()
                ->first(['control_id']);

            // ✅ Guardar asistente + marcar “cerrado / check-in final”
            DB::table('registros_checkin')
                ->where('id', $this->registroId)
                ->where('evento_id', $this->eventoId)
                ->update([
                    'asistente_nombre' => $this->asistenteNombre,
                    'asistente_telefono' => $this->asistenteTelefono,
                    'asistente_correo' => $this->asistenteCorreo,
                    'checked_in_at' => now(),
                    'checked_in_by_user_id' => auth()->id(),
                    'estado' => 'CHECKED_IN', // ✅ este estado lo vamos a usar para bloquear poderes
                    // ✅ snapshots para quórum
                    'coef_total_snapshot' => $this->coefTotal !== null ? (float) $this->coefTotal : null,
                    'cabeza_inmueble_snapshot' => $this->inmuebleLabel ?: null,
                    'updated_at' => now(),
                ]);

            // Cargar control a variables UI
            $this->controlNumero = null;
            $this->controlSerial = null;

            if ($reg2 && !is_null($reg2->control_id)) {
                $c = DB::table('controles')->where('id', $reg2->control_id)->first(['numero', 'serial']);
                $this->controlNumero = $c?->numero ?? null;
                $this->controlSerial = $c?->serial ?? null;
            }
        });

        $this->clearSelection();
        $this->checkinMsg = '✅ Check-in cerrado correctamente.';
    }

    public function closeCheckinMsg(): void
    {
        $this->checkinMsg = null;
        $this->dispatch('focus-field', id: 'checkinSearch');
    }

    public function hasUnsavedChanges(): bool
    {
        return ($this->asistenteNombre ?? '') !== ($this->asistenteNombreOriginal ?? '')
            || ($this->asistenteTelefono ?? '') !== ($this->asistenteTelefonoOriginal ?? '')
            || ($this->asistenteCorreo ?? '') !== ($this->asistenteCorreoOriginal ?? '');
    }

    public function hasPendingChanges(): bool
    {
        // Cambios en asistente (lo que ya tenías)
        if ($this->hasUnsavedChanges()) {
            return true;
        }

        // Cambios en grupo/poderes
        if ($this->dirtyGrupo) {
            return true;
        }

        // Control “en cola” listo para asignar
        if ($this->dirtyControl || $this->controlPendienteNumero || $this->controlPendienteId) {
            return true;
        }

        return false;
    }

    // ✅ Para usar en Blade como $hasUnsavedChanges (evita usar $this->... en el .blade)
    public function getHasUnsavedChangesProperty(): bool
    {
        return $this->hasUnsavedChanges();
    }

    public function requestClearSelection(): void
    {
        if ($this->registroId && $this->hasPendingChanges()) {
            $this->confirmSaveRequired = true;
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
            if ($this->tipoQuorum === 'nominal') {
                $this->selectPersona((int) $nextInmuebleId);
            } else {
                $this->selectInmueble((int) $nextInmuebleId);
            }
            return;
        }
    }

    // ---- (Dejamos tu assignControl por compatibilidad, pero ya NO lo usa el Blade si estás con confirmControl) ----
    public function assignControl(ControlService $controlService): void
    {
        $this->controlMsg = null;
        $this->controlError = null;

        if (!$this->eventoId) {
            $this->controlError = 'No hay evento activo en este puesto.';
            return;
        }

        if (!$this->registroId) {
            $this->controlError = 'Primero selecciona un inmueble.';
            return;
        }

        if (!$this->isCabezaSeleccionada) {
            $this->controlError = 'Este inmueble es un poder dentro de un grupo. El control debe asignarse desde la cabeza del grupo.';
            return;
        }

        $actual = DB::table('registros_checkin')
            ->where('evento_id', $this->eventoId)
            ->select('control_id')
            ->where('id', $this->registroId)
            ->first();

        if ($actual && !is_null($actual->control_id)) {
            $this->controlError = 'Este registro ya tiene un control asignado.';
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

        $registro = DB::table('registros_checkin')
            ->where('evento_id', $this->eventoId)
            ->select('control_id')
            ->where('id', $this->registroId)
            ->first();

        $this->controlNumero = null;
        $this->controlSerial = null;

        if ($registro && !is_null($registro->control_id)) {
            $control = DB::table('controles')->select('numero', 'serial')->where('id', $registro->control_id)->first();

            $this->controlNumero = $control?->numero ?? null;
            $this->controlSerial = $control?->serial ?? null;
        }

        $this->controlMsg = "✅ Control #{$num} asignado correctamente.";
        $this->controlNumeroInput = null;
    }

    private function resetControlUi(bool $full = false): void
    {
        $this->controlNumeroInput = null;
        $this->controlMsg = null;
        $this->controlError = null;

        $this->controlPreviewSerial = null;
        $this->controlPreviewEstado = null;

        if ($full) {
            $this->showControlModal = false;
            $this->controlModalTitle = null;
            $this->controlModalBody = null;
        }
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
        $this->controlSerial = null;

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
        $this->isCabezaSeleccionada = false;
        $this->coefTotal = null;
        $this->poderCount = null;

        $this->confirmDiscard = false;
        $this->pendingAction = null;
        $this->pendingInmuebleId = null;
        $this->propietarioLabel = null;
        $this->coefInmueble = null;

        $this->personaId    = null;
        $this->personaCedula = null;


        $this->resetControlUi(true);
        $this->resetPoderUi();

        $this->dirtyGrupo = false;
        $this->dirtyControl = false;
        $this->confirmSaveRequired = false;

        $this->search = '';
        $this->results = [];
    }

    public function render()
    {
        return view('livewire.checkin.registro-pantalla')
            ->layout('layouts.app');
    }
}