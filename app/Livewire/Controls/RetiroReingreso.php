<?php

namespace App\Livewire\Controls;

use Livewire\Component;
use App\Support\EventContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Throwable;

class RetiroReingreso extends Component
{
    // ===== Contexto =====
    public ?int $eventoId = null;
    public ?string $eventoTitulo = null;
    public string $tipoQuorum = 'coeficiente';

    // ===== Inputs =====
    public ?string $retiroNumero = null;
    public ?string $reingresoNumero = null;
    public ?string $reemplazoNumeroActual = null;
    public ?string $reemplazoNumeroNuevo = null;


    // ===== UI / Modal =====
    public bool $modalOpen = false;
    public string $modalType = 'success'; // success | error | info
    public string $modalTitle = '';
    public string $modalBody = '';
    public string $focusBackTo = 'retiroNumero';
    // ===== Info control actual (reemplazo) =====
    public ?string $reemplazoInfoNombre = null;
    public ?string $reemplazoInfoTelefono = null;
    public ?string $reemplazoInfoInmueble = null;
    public ?string $reemplazoInfoCedula = null;   // nominal: cédula de la persona
    public ?string $reemplazoInfoEstadoRegistro = null;
    public ?string $reemplazoInfoEstadoControl = null;
    public ?int $reemplazoRegistroId = null;
    public ?int $reemplazoControlId = null;
    // ===== Info control nuevo (reemplazo) =====
    public ?string $reemplazoNuevoEstadoControl = null;
    public ?string $reemplazoNuevoSerial = null;
    public ?int $reemplazoNuevoControlId = null;
    public bool $reemplazoNuevoOk = false;

    // ===== Consulta por control =====
    public ?string $consultaNumero = null;
    public bool $consultaReady = false;

    public ?string $consultaInmueble = null;
    public ?string $consultaPropietario = null;
    public ?string $consultaCedula = null;        // nominal: cédula de la persona
    public ?string $consultaAsistente = null;
    public ?string $consultaTelefono = null;
    public ?string $consultaEstadoRegistro = null;

    // retiroNumero | reingresoNumero


    private function syncEventoFromContext(): void
    {
        $ctx = app(EventContext::class);
        $this->eventoId   = $ctx->eventoId();
        $this->tipoQuorum = $ctx->tipoQuorum();
    }

    public function mount(): void
    {
        $this->syncEventoFromContext();

        if (!$this->eventoId) {
            session()->flash('no_evento_activo', true);
            $this->redirectRoute('eventos.index', navigate: true);
            return;
        }

        $this->eventoTitulo = 'Retiro / Reingreso de controles';
    }

    public function hydrate(): void
    {
        $this->syncEventoFromContext();
    }

    // =========================================================
    //  RETIRAR: marca el registro como RETIRADO (descuenta quórum)
    // =========================================================
    public function retirar(): void
    {
        $this->syncEventoFromContext();
        $eid = (int) ($this->eventoId ?? 0);
        $this->focusBackTo = 'retiroNumero';

        $numRaw = trim((string) $this->retiroNumero);
        $num = ctype_digit($numRaw) ? (int) $numRaw : 0;

        if ($eid <= 0) {
            $this->openModal('Sin evento activo', 'No hay un evento activo en el contexto del puesto.', 'error');
            return;
        }

        if ($num <= 0) {
            $this->openModal('Número inválido', 'Digita un número de control válido.', 'error');
            return;
        }

        try {
            DB::transaction(function () use ($eid, $num) {

                $control = DB::table('controles')
                    ->where('evento_id', $eid)
                    ->where('numero', $num)
                    ->first();

                if (!$control) {
                    $this->openModal('No existe', "No encontré el control #{$num} en este evento.", 'error');
                    return;
                }

                if (($control->estado ?? null) !== 'ASIGNADO') {
                    $this->openModal('No está asignado', "El control #{$num} está en estado '{$control->estado}'. Solo se puede retirar si está ASIGNADO.", 'error');
                    return;
                }

                // Buscamos el registro asociado (por control_id o por snapshot del número)
                $registro = DB::table('registros_checkin')
                    ->where('evento_id', $eid)
                    ->where(function ($q) use ($control, $num) {
                        $q->where('control_id', $control->id)
                            ->orWhere('control_numero_snapshot', $num);
                    })
                    ->orderByDesc('checked_in_at')
                    ->orderByDesc('id')
                    ->first();

                if (!$registro) {
                    $this->openModal('Sin check-in', "El control #{$num} está ASIGNADO pero no encontré registro de check-in asociado.", 'error');
                    return;
                }

                if (($registro->estado ?? null) !== 'CHECKED_IN') {
                    $this->openModal('Ya retirado', "El control #{$num} ya está marcado como retirado (estado actual: {$registro->estado}).", 'info');
                    return;
                }

                DB::table('registros_checkin')
                    ->where('id', $registro->id)
                    ->update([
                        'estado' => 'RETIRADO',
                        'retirado_at' => now()->max(\Carbon\Carbon::parse($registro->checked_in_at ?? now())),
                        'retirado_by_user_id' => Auth::id(),
                        'updated_at' => now(),
                    ]);

                // Mantenemos el control como ASIGNADO y ligado al registro (para poder reingresar con el mismo #)
                DB::table('controles')
                    ->where('id', $control->id)
                    ->update([
                        'estado' => 'ASIGNADO',
                        'asignado_a_registro_id' => $registro->id,
                        'updated_at' => now(),
                    ]);

                if ($this->tipoQuorum === 'nominal') {
                    $nombre = $registro->asistente_nombre ?? '—';
                    $votos  = (int) ($registro->coef_total_snapshot ?? 1);
                    $this->openModal(
                        'Control retirado',
                        "Control #{$num} retirado correctamente.\nPersona: {$nombre}\nVotos descontados: {$votos}",
                        'success'
                    );
                } else {
                    $inmueble = $registro->cabeza_inmueble_snapshot ?? $registro->inmueble_base_id ?? '—';
                    $coef     = $registro->coef_total_snapshot ?? 0;
                    $this->openModal(
                        'Control retirado',
                        "Control #{$num} retirado correctamente.\nInmueble/Grupo: {$inmueble}\nCoef descontado: " . number_format((float) $coef, 4),
                        'success'
                    );
                }
            });

        } catch (Throwable $e) {
            $this->openModal('Error', 'Ocurrió un error al retirar el control. Revisa el log para más detalle.', 'error');
            return;
        } finally {
            $this->retiroNumero = null;
        }
    }

    // =========================================================
    //  REINGRESAR: vuelve a CHECKED_IN (vuelve a sumar quórum)
    // =========================================================
    public function reingresar(): void
    {
        $this->syncEventoFromContext();
        $eid = (int) ($this->eventoId ?? 0);
        $this->focusBackTo = 'reingresoNumero';

        $numRaw = trim((string) $this->reingresoNumero);
        $num = ctype_digit($numRaw) ? (int) $numRaw : 0;

        if ($eid <= 0) {
            $this->openModal('Sin evento activo', 'No hay un evento activo en el contexto del puesto.', 'error');
            return;
        }

        if ($num <= 0) {
            $this->openModal('Número inválido', 'Digita un número de control válido.', 'error');
            return;
        }

        try {
            DB::transaction(function () use ($eid, $num) {

                $control = DB::table('controles')
                    ->where('evento_id', $eid)
                    ->where('numero', $num)
                    ->first();

                if (!$control) {
                    $this->openModal('No existe', "No encontré el control #{$num} en este evento.", 'error');
                    return;
                }

                if (($control->estado ?? null) !== 'ASIGNADO') {
                    $this->openModal('No está asignado', "El control #{$num} está en estado '{$control->estado}'. Para reingresar debe estar ASIGNADO.", 'error');
                    return;
                }

                $registro = DB::table('registros_checkin')
                    ->where('evento_id', $eid)
                    ->where(function ($q) use ($control, $num) {
                        $q->where('control_id', $control->id)
                            ->orWhere('control_numero_snapshot', $num);
                    })
                    ->orderByDesc('checked_in_at')
                    ->orderByDesc('id')
                    ->first();

                if (!$registro) {
                    $this->openModal('Sin historial', "No encontré registro asociado al control #{$num}.", 'error');
                    return;
                }

                if (($registro->estado ?? null) === 'CHECKED_IN') {
                    $this->openModal('Ya está activo', "El control #{$num} ya está activo (CHECKED_IN).", 'info');
                    return;
                }

                if (($registro->estado ?? null) !== 'RETIRADO') {
                    $this->openModal('Estado inválido', "El registro asociado está en estado '{$registro->estado}'. Se esperaba RETIRADO.", 'error');
                    return;
                }

                $now = now();

                DB::table('registros_checkin')
                    ->where('id', $registro->id)
                    ->update([
                        'estado' => 'CHECKED_IN',

                        // ✅ NO tocar checked_in_at aquí (eso evita el retiro < checkin)
                        'reingreso_at' => $now,
                        'reingreso_by_user_id' => Auth::id(),

                        'updated_at' => $now,
                    ]);

                DB::table('controles')
                    ->where('id', $control->id)
                    ->update([
                        'estado' => 'ASIGNADO',
                        'asignado_a_registro_id' => $registro->id,
                        'updated_at' => now(),
                    ]);

                if ($this->tipoQuorum === 'nominal') {
                    $nombre = $registro->asistente_nombre ?? '—';
                    $votos  = (int) ($registro->coef_total_snapshot ?? 1);
                    $this->openModal(
                        'Control reingresado',
                        "Control #{$num} reingresado correctamente.\nPersona: {$nombre}\nVotos sumados: {$votos}",
                        'success'
                    );
                } else {
                    $inmueble = $registro->cabeza_inmueble_snapshot ?? $registro->inmueble_base_id ?? '—';
                    $coef     = $registro->coef_total_snapshot ?? 0;
                    $this->openModal(
                        'Control reingresado',
                        "Control #{$num} reingresado correctamente.\nInmueble/Grupo: {$inmueble}\nCoef sumado: " . number_format((float) $coef, 4),
                        'success'
                    );
                }
            });

        } catch (Throwable $e) {
            $this->openModal('Error', 'Ocurrió un error al reingresar el control. Revisa el log para más detalle.', 'error');
            return;
        } finally {
            $this->reingresoNumero = null;
        }
    }

    // =========================================================
    //  BUSCAR CONTROL ACTUAL (solo lectura para mostrar dueño)
    // =========================================================
    public function buscarControlActual(): void
    {
        $this->syncEventoFromContext();
        $eid = (int) ($this->eventoId ?? 0);

        // reset info previa
        $this->reemplazoInfoNombre = null;
        $this->reemplazoInfoTelefono = null;
        $this->reemplazoInfoInmueble = null;
        $this->reemplazoInfoCedula = null;
        $this->reemplazoInfoEstadoRegistro = null;
        $this->reemplazoInfoEstadoControl = null;
        $this->reemplazoRegistroId = null;
        $this->reemplazoControlId = null;

        $numRaw = trim((string) $this->reemplazoNumeroActual);
        $num = ctype_digit($numRaw) ? (int) $numRaw : 0;

        if ($eid <= 0) {
            $this->openModal('Sin evento activo', 'No hay un evento activo en el contexto del puesto.', 'error');
            return;
        }

        if ($num <= 0) {
            $this->openModal('Número inválido', 'Digita un número de control válido.', 'error');
            return;
        }

        $control = DB::table('controles')
            ->where('evento_id', $eid)
            ->where('numero', $num)
            ->first();

        if (!$control) {
            $this->openModal('No existe', "No encontré el control #{$num} en este evento.", 'error');
            return;
        }

        // Tomamos el último registro asociado a ese control (como haces en retirar/reingresar)
        $registro = DB::table('registros_checkin')
            ->where('evento_id', $eid)
            ->where(function ($q) use ($control, $num) {
                $q->where('control_id', $control->id)
                    ->orWhere('control_numero_snapshot', $num);
            })
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id')
            ->first();

        if (!$registro) {
            $this->openModal('Sin historial', "El control #{$num} existe, pero no encontré registro de check-in asociado.", 'error');
            return;
        }

        // Guardamos info para mostrar en UI
        $this->reemplazoControlId = (int) $control->id;
        $this->reemplazoRegistroId = (int) $registro->id;

        $this->reemplazoInfoNombre   = $registro->asistente_nombre ?? '—';
        $this->reemplazoInfoTelefono = $registro->asistente_telefono ?? '—';

        if ($this->tipoQuorum === 'nominal' && $registro->persona_id) {
            $persona = DB::table('evento_personas')
                ->where('id', (int) $registro->persona_id)
                ->first(['cedula']);
            $this->reemplazoInfoCedula  = $persona?->cedula ?? '—';
            $this->reemplazoInfoInmueble = null;
        } else {
            $this->reemplazoInfoInmueble = (string) ($registro->cabeza_inmueble_snapshot ?? $registro->inmueble_base_id ?? '—');
            $this->reemplazoInfoCedula   = null;
        }

        $this->reemplazoInfoEstadoRegistro = (string) ($registro->estado ?? '—');
        $this->reemplazoInfoEstadoControl  = (string) ($control->estado ?? '—');

        // Deja listo el foco en el nuevo control
        $this->dispatch('focus-field', id: 'reemplazoNumeroNuevo');
    }

    // =========================================================
    //  BUSCAR CONTROL NUEVO (solo lectura para validar LIBRE)
    // =========================================================
    public function buscarControlNuevo(): void
    {
        $this->syncEventoFromContext();
        $eid = (int) ($this->eventoId ?? 0);

        // reset info previo del nuevo
        $this->reemplazoNuevoEstadoControl = null;
        $this->reemplazoNuevoSerial = null;
        $this->reemplazoNuevoControlId = null;
        $this->reemplazoNuevoOk = false;

        $numRaw = trim((string) $this->reemplazoNumeroNuevo);
        $num = ctype_digit($numRaw) ? (int) $numRaw : 0;

        if ($eid <= 0) {
            $this->openModal('Sin evento activo', 'No hay un evento activo en el contexto del puesto.', 'error');
            return;
        }

        if ($num <= 0) {
            $this->openModal('Número inválido', 'Digita un número de control válido.', 'error');
            return;
        }

        if ($this->reemplazoRegistroId === null) {
            $this->openModal('Falta control actual', 'Primero digita el control actual y presiona Enter para cargar el dueño.', 'info');
            $this->dispatch('focus-field', id: 'reemplazoNumeroActual');
            return;
        }

        $control = DB::table('controles')
            ->where('evento_id', $eid)
            ->where('numero', $num)
            ->first();

        if (!$control) {
            $this->openModal('No existe', "No encontré el nuevo control #{$num} en este evento.", 'error');
            return;
        }

        // Guardamos info para UI
        $this->reemplazoNuevoControlId = (int) $control->id;
        $this->reemplazoNuevoEstadoControl = (string) ($control->estado ?? '—');
        $this->reemplazoNuevoSerial = (string) ($control->serial ?? '—');

        if (($control->estado ?? null) !== 'LIBRE') {
            $this->reemplazoNuevoOk = false;
            $this->openModal(
                'Nuevo control no disponible',
                "El control #{$num} está en estado '{$control->estado}'. Debe estar LIBRE para poder reemplazar.",
                'error'
            );
            return;
        }

        $this->reemplazoNuevoOk = true;

        // Dejamos listo para que el operador solo oprima el botón
        $this->dispatch('focus-field', id: 'btnReemplazarControl');
    }


    // =========================================================
    //  REEMPLAZAR CONTROL (DB real, sin crear ni borrar registros)
    // =========================================================
    public function reemplazarControl(): void
    {
        $this->syncEventoFromContext();
        $eid = (int) ($this->eventoId ?? 0);

        $this->focusBackTo = 'reemplazoNumeroActual';

        $actualRaw = trim((string) $this->reemplazoNumeroActual);
        $nuevoRaw = trim((string) $this->reemplazoNumeroNuevo);

        $actual = ctype_digit($actualRaw) ? (int) $actualRaw : 0;
        $nuevo = ctype_digit($nuevoRaw) ? (int) $nuevoRaw : 0;

        if ($eid <= 0) {
            $this->openModal('Sin evento activo', 'No hay un evento activo en el contexto del puesto.', 'error');
            return;
        }

        if ($actual <= 0 || $nuevo <= 0) {
            $this->openModal('Datos inválidos', 'Debes digitar un número válido para el control actual y el nuevo.', 'error');
            return;
        }

        if ($actual === $nuevo) {
            $this->openModal('Controles iguales', 'El control actual y el nuevo no pueden ser el mismo.', 'error');
            return;
        }

        if (!$this->reemplazoRegistroId || !$this->reemplazoControlId) {
            $this->openModal('Falta control actual', 'Primero digita el control actual y presiona Enter para cargar el dueño.', 'info');
            $this->dispatch('focus-field', id: 'reemplazoNumeroActual');
            return;
        }

        if (!$this->reemplazoNuevoOk || !$this->reemplazoNuevoControlId) {
            $this->openModal('Nuevo control no validado', 'Valida el nuevo control con Enter. Debe existir y estar LIBRE.', 'info');
            $this->dispatch('focus-field', id: 'reemplazoNumeroNuevo');
            return;
        }

        try {
            DB::transaction(function () use ($eid, $actual, $nuevo) {

                // Re-leemos dentro de transacción (estado actual real)
                $controlViejo = DB::table('controles')
                    ->where('evento_id', $eid)
                    ->where('numero', $actual)
                    ->lockForUpdate()
                    ->first();

                $controlNuevo = DB::table('controles')
                    ->where('evento_id', $eid)
                    ->where('numero', $nuevo)
                    ->lockForUpdate()
                    ->first();

                if (!$controlViejo) {
                    $this->openModal('No existe', "No encontré el control actual #{$actual} en este evento.", 'error');
                    return;
                }

                if (!$controlNuevo) {
                    $this->openModal('No existe', "No encontré el nuevo control #{$nuevo} en este evento.", 'error');
                    return;
                }

                if (($controlNuevo->estado ?? null) !== 'LIBRE') {
                    $this->openModal(
                        'Nuevo control no disponible',
                        "El control #{$nuevo} está en estado '{$controlNuevo->estado}'. Debe estar LIBRE.",
                        'error'
                    );
                    return;
                }

                // Registro objetivo: el “último válido” que cargamos en pantalla
                $registro = DB::table('registros_checkin')
                    ->where('evento_id', $eid)
                    ->where('id', (int) $this->reemplazoRegistroId)
                    ->lockForUpdate()
                    ->first();

                if (!$registro) {
                    $this->openModal('Registro no encontrado', 'No encontré el registro asociado para reemplazar.', 'error');
                    return;
                }

                // Seguridad: no hacemos reemplazo si el registro no tiene control o no coincide con el viejo
                // (evita reemplazar accidentalmente un registro distinto)
                $registroControlId = (int) ($registro->control_id ?? 0);
                if ($registroControlId <= 0) {
                    $this->openModal('Sin control', 'El registro asociado no tiene control_id. No se puede reemplazar.', 'error');
                    return;
                }

                if ($registroControlId !== (int) $controlViejo->id) {
                    $this->openModal(
                        'Desfase detectado',
                        "El registro ya no está asociado al control #{$actual}. Recarga el control actual (Enter) e intenta de nuevo.",
                        'error'
                    );
                    return;
                }

                // 1) Actualizar registro_checkin: apunta al nuevo control (sin tocar coef/grupo/inmueble)
                DB::table('registros_checkin')
                    ->where('id', (int) $registro->id)
                    ->update([
                        'control_id' => (int) $controlNuevo->id,
                        'control_numero_snapshot' => (int) $controlNuevo->numero,
                        'control_serial_snapshot' => (string) $controlNuevo->serial,
                        'updated_at' => now(),
                    ]);

                // 2) Liberar control viejo
                DB::table('controles')
                    ->where('id', (int) $controlViejo->id)
                    ->update([
                        'estado' => 'LIBRE',
                        'asignado_a_registro_id' => null,
                        'updated_at' => now(),
                    ]);

                // 3) Asignar control nuevo
                DB::table('controles')
                    ->where('id', (int) $controlNuevo->id)
                    ->update([
                        'estado' => 'ASIGNADO',
                        'asignado_a_registro_id' => (int) $registro->id,
                        'updated_at' => now(),
                    ]);

                // 4) Modal éxito
                $nombre = $registro->asistente_nombre ?? '—';
                $tel = $registro->asistente_telefono ?? '—';
                $this->openModal(
                    'Control reemplazado',
                    "Reemplazo exitoso.\n{$nombre} ({$tel})\n#{$actual} → #{$nuevo}",
                    'success'
                );
            });

        } catch (Throwable $e) {
            $this->openModal('Error', 'Ocurrió un error al reemplazar el control. Revisa el log para más detalle.', 'error');
            return;
        } finally {
            // limpiamos inputs y estado de validación para seguir rápido
            $this->reemplazoNumeroActual = null;
            $this->reemplazoNumeroNuevo = null;

            $this->reemplazoNuevoOk = false;
            $this->reemplazoNuevoControlId = null;
            $this->reemplazoNuevoEstadoControl = null;
            $this->reemplazoNuevoSerial = null;

            $this->reemplazoRegistroId = null;
            $this->reemplazoControlId = null;

            $this->reemplazoInfoNombre = null;
            $this->reemplazoInfoTelefono = null;
            $this->reemplazoInfoInmueble = null;
            $this->reemplazoInfoCedula = null;
            $this->reemplazoInfoEstadoRegistro = null;
            $this->reemplazoInfoEstadoControl = null;
        }
    }

    // =========================================================
    //  CONSULTAR CONTROL (solo lectura)
    // =========================================================
    public function consultarControl(): void
    {
        $this->syncEventoFromContext();
        $eid = (int) ($this->eventoId ?? 0);

        // reset resultado
        $this->consultaReady = false;
        $this->consultaInmueble = null;
        $this->consultaPropietario = null;
        $this->consultaCedula = null;
        $this->consultaAsistente = null;
        $this->consultaTelefono = null;
        $this->consultaEstadoRegistro = null;

        $numRaw = trim((string) $this->consultaNumero);
        $num = ctype_digit($numRaw) ? (int) $numRaw : 0;

        if ($eid <= 0) {
            $this->openModal('Sin evento activo', 'No hay un evento activo en el contexto del puesto.', 'error');
            return;
        }

        if ($num <= 0) {
            $this->openModal('Número inválido', 'Digita un número de control válido.', 'error');
            return;
        }

        // 1) Control existe en el evento
        $control = DB::table('controles')
            ->where('evento_id', $eid)
            ->where('numero', $num)
            ->first();

        if (!$control) {
            $this->openModal('No existe', "No encontré el control #{$num} en este evento.", 'error');
            return;
        }

        // 2) Último registro asociado a ese control (igual que tu lógica)
        $registro = DB::table('registros_checkin')
            ->where('evento_id', $eid)
            ->where(function ($q) use ($control, $num) {
                $q->where('control_id', $control->id)
                    ->orWhere('control_numero_snapshot', $num);
            })
            ->orderByDesc('checked_in_at')
            ->orderByDesc('id')
            ->first();

        if (!$registro) {
            $this->openModal('Sin historial', "El control #{$num} existe, pero no encontré registro asociado en check-in.", 'info');
            return;
        }

        // 3) Pintamos datos disponibles desde el registro
        $this->consultaAsistente      = (string) ($registro->asistente_nombre ?? '—');
        $this->consultaTelefono       = (string) ($registro->asistente_telefono ?? '—');
        $this->consultaEstadoRegistro = (string) ($registro->estado ?? '—');

        if ($this->tipoQuorum === 'nominal') {
            // Modo nominal: mostramos cédula de la persona (inmueble no aplica)
            if ($registro->persona_id) {
                $persona = DB::table('evento_personas')
                    ->where('id', (int) $registro->persona_id)
                    ->first(['cedula']);
                $this->consultaCedula = $persona?->cedula ?? '—';
            } else {
                $this->consultaCedula = '—';
            }
            $this->consultaInmueble    = null;
            $this->consultaPropietario = null;
        } else {
            // Modo coeficiente: inmueble + propietario del padrón
            $this->consultaInmueble = (string) ($registro->cabeza_inmueble_snapshot ?? $registro->inmueble_base_id ?? '—');
            $inmuebleLabel = (string) ($registro->cabeza_inmueble_snapshot ?? $registro->inmueble_base_id ?? '');
            $padron = DB::table('evento_padron')
                ->where('evento_id', $eid)
                ->where('inmueble', $inmuebleLabel)
                ->first(['propietario']);
            $this->consultaPropietario = $padron?->propietario ?: '—';
            $this->consultaCedula      = null;
        }


        $this->consultaReady = true;

        // UX: si quieres hacer muchas consultas seguidas
        $this->dispatch('focus-field', id: 'consultaNumero');
    }

    // ===== Modal helpers =====
    private function openModal(string $title, string $body, string $type = 'success'): void
    {
        $this->modalOpen = true;
        $this->modalTitle = $title;
        $this->modalBody = $body;
        $this->modalType = $type;
    }

    public function closeModal(): void
    {
        $this->modalOpen = false;
        $this->modalTitle = '';
        $this->modalBody = '';
        $this->modalType = 'success';

        $this->dispatch('focus-field', id: $this->focusBackTo);
    }

    public function render()
    {
        return view('livewire.controls.retiro-reingreso')
            ->layout('layouts.app');
    }
}
