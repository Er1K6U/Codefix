<div class="max-w-7xl mx-auto p-6 space-y-5">

    {{-- ✅ MODAL ÉXITO (más visible) --}}
    @if($checkinMsg)
        <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-3xl bg-white border border-gray-200 shadow-2xl p-7">
                <div class="flex items-start gap-4">
                    <div class="mt-1 w-12 h-12 rounded-2xl bg-emerald-50 border border-emerald-200 flex items-center justify-center">
                        <svg class="w-7 h-7 text-emerald-600" viewBox="0 0 24 24" fill="none">
                            <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>

                    <div class="flex-1">
                        <h3 class="text-lg font-black text-gray-900">¡Listo! Check-in cerrado</h3>
                        <p class="text-sm text-gray-600 mt-1">
                            {{ $checkinMsg }}
                        </p>

                        <div class="mt-5 flex justify-end">
                            <button
                                type="button"
                                wire:click="$set('checkinMsg', null)"
                                class="px-5 py-2 rounded-xl bg-black text-white font-semibold hover:bg-black/90 transition"
                            >
                                Perfecto
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ✅ MODAL ERROR (si quieres también visible; si no lo quieres, lo quitas) --}}
    @if($checkinError)
        <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-3xl bg-white border border-gray-200 shadow-2xl p-7">
                <div class="flex items-start gap-4">
                    <div class="mt-1 w-12 h-12 rounded-2xl bg-red-50 border border-red-200 flex items-center justify-center">
                        <svg class="w-7 h-7 text-red-600" viewBox="0 0 24 24" fill="none">
                            <path d="M12 9v4m0 4h.01M10.29 3.86l-7.4 13.2A2 2 0 0 0 4.64 20h14.72a2 2 0 0 0 1.75-2.94l-7.4-13.2a2 2 0 0 0-3.42 0Z"
                                  stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>

                    <div class="flex-1">
                        <h3 class="text-lg font-black text-gray-900">Atención</h3>
                        <p class="text-sm text-gray-600 mt-1">
                            {{ $checkinError }}
                        </p>

                        <div class="mt-5 flex justify-end">
                            <button
                                type="button"
                                wire:click="$set('checkinError', null)"
                                class="px-5 py-2 rounded-xl bg-black text-white font-semibold hover:bg-black/90 transition"
                            >
                                Entendido
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ✅ FILA SUPERIOR: 2 columnas (Buscador a la derecha + Inmueble al lado) --}}
    <div class="grid lg:grid-cols-2 gap-6 items-start">

        {{-- ✅ Registro presencial / Check-in (AZUL) --}}
        <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-5 border-l-4 border-l-indigo-400">
            <div class="flex items-start justify-between gap-4">
                <div class="min-w-0">
                    <h1 class="text-xl font-black text-gray-900">Registro presencial / Check-in</h1>
                    <p class="text-sm text-gray-600 mt-1">
                        Busca por inmueble (torre + número o número).
                    </p>
                </div>

                <div class="w-[360px] max-w-full">
                    <div class="relative">
                        <input
                            type="text"
                            wire:model.live="search"
                            placeholder="Ej: T3 3502 o 3502"
                            @disabled($registroId && $this->hasUnsavedChanges())
                            class="w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-indigo-300 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
                        />

                        @if(!empty($results) && !($registroId && $this->hasUnsavedChanges()))
                            <div class="absolute z-50 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden max-h-64 overflow-y-auto">
                                @foreach($results as $r)
                                    <button type="button" wire:click="requestSelectInmueble({{ $r['id'] }})"
                                        class="w-full text-left px-4 py-3 hover:bg-gray-50">
                                        <div class="font-semibold text-gray-900">{{ $r['label'] }}</div>
                                        <div class="text-xs text-gray-500">Seleccionar</div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    @if($registroId && $this->hasUnsavedChanges())
                        <p class="text-xs text-amber-700 mt-2">
                            Tienes cambios sin guardar. Guarda o descarta para buscar otro inmueble.
                        </p>
                    @endif
                </div>
            </div>
        </div>

        {{-- ✅ Inmueble / Resumen (VERDE) --}}
        <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-5 border-l-4 border-l-emerald-400">
            <div class="flex items-start justify-between gap-6">
                <div class="min-w-0">
                    <div class="flex items-center gap-3">
                        <div class="text-sm text-gray-500">Inmueble</div>
                        <div class="text-lg font-black text-gray-900">
                            {{ $inmuebleLabel ?? '—' }}
                        </div>
                    </div>

                    <div class="mt-2 grid sm:grid-cols-2 gap-x-8 gap-y-2 text-sm text-gray-700">
                        <div class="min-w-0">
                            <span class="font-semibold text-gray-800">Propietario:</span>
                            <span class="ml-1 break-words">{{ $propietarioLabel ?? '—' }}</span>
                        </div>

                        <div>
                            <span class="font-semibold text-gray-800">Coeficiente:</span>
                            <span class="ml-1">{{ is_null($coefInmueble) ? '—' : number_format($coefInmueble, 4, '.', '') }}</span>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2">
                        <span class="inline-flex items-center px-3 py-1 rounded-full border text-xs font-semibold">
                            Estado: {{ $estado ?? '—' }}
                        </span>

                        @if($grupoId)
                            <span class="inline-flex items-center px-3 py-1 rounded-full border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-700">
                                Cabeza: <span class="ml-1 text-gray-900">{{ $miembros[0]['inmueble'] ?? '—' }}</span>
                            </span>

                            @if(isset($isCabezaSeleccionada) && !$isCabezaSeleccionada)
                                <span class="inline-flex items-center px-3 py-1 rounded-full border border-red-200 bg-red-50 text-xs font-semibold text-red-700">
                                    PODER (control bloqueado)
                                </span>
                            @endif
                        @endif
                    </div>
                </div>

                {{-- Panel control a la derecha --}}
                <div class="w-[240px] shrink-0">
                    <div class="rounded-2xl border border-emerald-200 bg-emerald-50/50 p-4">
                        <div class="flex items-center justify-between">
                            <div class="text-sm text-gray-600">Control</div>
                            <div class="flex items-center gap-2">
                                <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                <span class="text-xs font-bold text-emerald-700">ON</span>
                            </div>
                        </div>

                        <div class="mt-1 text-lg font-black text-gray-900">
                            @if($controlNumero)
                                #{{ $controlNumero }}
                                @if($controlSerial)
                                    <span class="text-sm font-semibold text-gray-600">({{ $controlSerial }})</span>
                                @endif
                            @else
                                —
                            @endif
                        </div>

                        <div class="mt-2 text-xs text-gray-600">
                            Vista rápida del control asignado al inmueble/grupo.
                        </div>
                    </div>
                </div>
            </div>

            @if(!$registroId)
                <div class="mt-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                    Busca un inmueble arriba para cargar los datos.
                </div>
            @endif
        </div>
    </div>

    {{-- ✅ Representación / Poderes (VERDE suave) --}}
    <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6 border-l-4 border-l-emerald-400">
        <div class="flex items-start justify-between gap-6">
            <div>
                <h2 class="text-lg font-black text-gray-900">Representación / Poderes</h2>
                <p class="text-sm text-gray-600 mt-1">Cabeza + inmuebles representados.</p>
            </div>

            <div class="text-right">
                <div class="text-sm text-gray-500">Coef. total grupo</div>
                <div class="text-lg font-black text-gray-900">
                    {{ is_null($coefTotal) ? '—' : number_format($coefTotal, 4, '.', '') }}
                </div>
                @if(!is_null($poderCount))
                    <div class="text-xs text-gray-500 mt-1">
                        Poderes: <span class="font-semibold text-gray-800">{{ $poderCount }}</span>
                    </div>
                @endif
            </div>
        </div>

        @if($registroId)
            <div class="mt-5 flex flex-wrap items-end gap-4">
                <div class="flex items-center gap-3 w-full lg:w-auto">
                    <label class="text-sm font-semibold text-gray-700 whitespace-nowrap">Agregar poder</label>

                    <div class="relative w-full lg:w-[520px]"
                        x-data="{ open: false }"
                        x-on:click.away="open = false"
                        x-on:keydown.escape.window="open = false">
                        <input
                            type="text"
                            wire:model.live="poderSearch"
                            x-on:focus="open = true"
                            x-on:input="open = true"
                            placeholder="Ej: 3504 o Juan Pérez"
                            class="w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-emerald-200"
                        />

                        @if(!empty($poderResults))
                            <div x-show="open" x-transition
                                class="absolute z-40 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden max-h-60 overflow-y-auto">
                                @foreach($poderResults as $r)
                                    <button
                                        type="button"
                                        wire:click="addPoder({{ $r['id'] }})"
                                        x-on:click="open = false"
                                        class="w-full text-left px-4 py-3 hover:bg-gray-50">
                                        <div class="flex items-center justify-between gap-3">
                                            <div class="min-w-0">
                                                <div class="font-semibold text-gray-900 truncate">{{ $r['label'] }}</div>
                                                <div class="text-xs text-gray-500 truncate">{{ $r['propietario'] }}</div>
                                            </div>
                                            <div class="text-xs font-semibold text-gray-700 shrink-0">
                                                coef: {{ number_format($r['coef'], 4, '.', '') }}
                                            </div>
                                        </div>
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                <div class="flex-1 min-w-[220px]">
                    @if($poderError)
                        <p class="text-xs text-red-600 font-semibold">{{ $poderError }}</p>
                    @endif
                    @if($poderMsg)
                        <p class="text-xs text-emerald-700 font-semibold">{{ $poderMsg }}</p>
                    @endif
                </div>
            </div>

            {{-- Tabla con scroll vertical sutil --}}
            <div class="mt-5 border border-gray-200 rounded-2xl overflow-hidden">
                <div class="grid grid-cols-12 gap-3 px-4 py-3 bg-emerald-50 text-xs font-semibold text-gray-600">
                    <div class="col-span-3">Inmueble</div>
                    <div class="col-span-6">Propietario</div>
                    <div class="col-span-2 text-right">Coef</div>
                    <div class="col-span-1 text-right">Acción</div>
                </div>

                <div class="max-h-52 overflow-y-auto">
                    @forelse($miembros as $m)
                        <div class="grid grid-cols-12 gap-3 px-4 py-3 border-t border-gray-200 items-center">
                            <div class="col-span-3 min-w-0">
                                <div class="font-semibold text-gray-900 truncate">
                                    {{ $m['inmueble'] }}

                                    @if($m['es_cabeza'])
                                        <span class="ml-2 text-[11px] px-2 py-0.5 rounded-full border border-black bg-black text-white font-black">
                                            CABEZA
                                        </span>
                                    @else
                                        <span class="ml-2 text-[11px] px-2 py-0.5 rounded-full border border-gray-200 text-gray-600">
                                            Poder
                                        </span>
                                    @endif
                                </div>
                            </div>

                            <div class="col-span-6 text-sm text-gray-700 truncate">
                                {{ $m['propietario'] }}
                            </div>

                            <div class="col-span-2 text-right text-sm font-semibold text-gray-900">
                                {{ number_format($m['coeficiente'], 4, '.', '') }}
                            </div>

                            <div class="col-span-1 text-right">
                                @if(!$m['es_cabeza'])
                                    <button type="button"
                                        wire:click="removePoder({{ $m['miembro_id'] }})"
                                        class="text-xs px-3 py-1 rounded-lg border border-gray-300 hover:bg-gray-50 font-semibold">
                                        Quitar
                                    </button>
                                @else
                                    <span class="text-xs text-gray-400">—</span>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-4 text-sm text-gray-600">
                            No hay miembros cargados.
                        </div>
                    @endforelse
                </div>
            </div>
        @else
            <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                Aquí aparecerá la representación cuando selecciones un inmueble.
            </div>
        @endif
    </div>

    {{-- ✅ Control de votación (NARANJA) --}}
    <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6 border-l-4 border-l-amber-400">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h2 class="text-lg font-black text-gray-900">Control de votación</h2>
                <p class="text-sm text-gray-600 mt-1">
                    Digita el número del control. Se valida con <span class="font-semibold">Tab</span>/<span class="font-semibold">Enter</span>.
                </p>
            </div>

            {{-- Input al lado para ahorrar espacio --}}
            <div class="w-full sm:w-[420px]">
                @if($registroId)
                    @if($isCabezaSeleccionada)
                        <label class="text-sm font-semibold text-gray-700">Número de control</label>

                        <input
                            type="text"
                            wire:model.live="controlNumeroInput"
                            wire:keydown.enter.prevent="confirmControl"
                            wire:blur="confirmControl"
                            placeholder="Ej: 27"
                            class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-amber-200 @if($controlError) border-red-500 @endif"
                        />

                        @if($controlPreviewSerial)
                            <div class="mt-2 text-xs text-gray-600">
                                Código: <span class="font-semibold text-gray-900">{{ $controlPreviewSerial }}</span>

                                @if($controlPreviewEstado && $controlPreviewEstado !== 'LIBRE')
                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full border border-red-200 bg-red-50 text-red-700 font-semibold">
                                        {{ $controlPreviewEstado }}
                                    </span>
                                @else
                                    <span class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full border border-emerald-200 bg-emerald-50 text-emerald-700 font-semibold">
                                        LISTO
                                    </span>
                                @endif
                            </div>
                        @else
                            <div class="mt-2 text-xs text-gray-500">
                                Confirma con <span class="font-semibold">Tab</span> o <span class="font-semibold">Enter</span>.
                            </div>
                        @endif

                        @if($controlError)
                            <p class="text-xs text-red-600 mt-2 font-semibold">{{ $controlError }}</p>
                        @endif

                        @if($controlMsg)
                            <div class="mt-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 font-semibold">
                                {{ $controlMsg }}
                            </div>
                        @endif
                    @else
                        <div class="mt-1 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                            Este inmueble es un <span class="font-semibold">PODER</span>. El control se gestiona desde la <span class="font-semibold">cabeza</span>.
                        </div>
                    @endif
                @else
                    <div class="mt-2 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                        Aquí aparecerá la validación del control cuando selecciones un inmueble.
                    </div>
                @endif
            </div>
        </div>
    </div>

    {{-- ✅ Asistente presente (MORADO) --}}
    <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6 border-l-4 border-l-fuchsia-300">
        <div class="flex items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-black text-gray-900">Asistente presente</h2>
                <p class="text-sm text-gray-600 mt-1">Datos obligatorios para cerrar el check-in.</p>
            </div>
        </div>

        {{-- Labels al lado del input para ahorrar espacio --}}
        <div class="mt-5 grid lg:grid-cols-2 gap-4">
            <div class="flex items-center gap-3">
                <label class="text-sm font-semibold text-gray-700 w-44 shrink-0">Nombre (opcional)</label>
                <input
                    type="text"
                    wire:model.defer="asistenteNombre"
                    class="w-full rounded-xl border-gray-300"
                    placeholder="Ej: Carlos Pérez"
                />
            </div>

            <div class="flex items-center gap-3">
                <label class="text-sm font-semibold text-gray-700 w-44 shrink-0">
                    Teléfono <span class="text-red-600">*</span>
                </label>
                <div class="w-full">
                    <input
                        type="text"
                        wire:model.defer="asistenteTelefono"
                        placeholder="Obligatorio"
                        class="w-full rounded-xl border-gray-300 @if($errorTelefono) border-red-500 @endif"
                    />
                    @if($errorTelefono)
                        <p class="text-xs text-red-600 mt-1 font-semibold">{{ $errorTelefono }}</p>
                    @endif
                </div>
            </div>

            {{-- Correo + Botones en la misma fila --}}
            <div class="lg:col-span-2 flex flex-wrap items-center gap-3">
                <label class="text-sm font-semibold text-gray-700 w-44 shrink-0">Correo (opcional)</label>

                <input
                    type="email"
                    wire:model.defer="asistenteCorreo"
                    class="flex-1 min-w-[240px] rounded-xl border-gray-300"
                    placeholder="Ej: correo@dominio.com"
                />

                <button
                    type="button"
                    wire:click="saveAsistente"
                    class="px-6 py-2 rounded-xl bg-black text-white font-semibold hover:bg-black/90 transition"
                >
                    Guardar asistente
                </button>

                <button
                    type="button"
                    wire:click="requestClearSelection"
                    class="px-5 py-2 rounded-xl border border-gray-300 hover:bg-gray-50 font-semibold transition"
                >
                    Limpiar
                </button>
            </div>
        </div>

        <div class="mt-3 text-xs text-gray-500">
            * El teléfono es obligatorio para poder cerrar el check-in.
        </div>
    </div>

    {{-- Modal: cambios sin guardar --}}
    @if($confirmDiscard)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-2xl bg-white border border-gray-200 shadow-2xl p-6">
                <h3 class="text-lg font-black text-gray-900">Cambios sin guardar</h3>
                <p class="text-sm text-gray-600 mt-2">
                    Tienes datos del asistente que no se han guardado. ¿Deseas descartarlos y continuar?
                </p>

                <div class="mt-5 flex justify-end gap-3">
                    <button type="button" wire:click="cancelDiscard"
                        class="px-4 py-2 rounded-xl border border-gray-300 hover:bg-gray-50 font-semibold">
                        Cancelar
                    </button>

                    <button type="button" wire:click="discardChangesAndProceed"
                        class="px-4 py-2 rounded-xl bg-black text-white font-semibold hover:bg-black/90">
                        Sí, descartar
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>
