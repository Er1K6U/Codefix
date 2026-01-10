<div class="max-w-7xl mx-auto p-6 space-y-6">

    {{-- Buscador --}}
    <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6">
        <h1 class="text-xl font-black text-gray-900">Registro presencial / Check-in</h1>
        <p class="text-sm text-gray-600 mt-1">Busca por inmueble (torre + número o número).</p>

        <div class="mt-4 relative">
            <input
                type="text"
                wire:model.live="search"
                placeholder="Ej: T3 3502 o 3502"
                @disabled($registroId && $this->hasUnsavedChanges())
                class="w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-black/20 disabled:bg-gray-100 disabled:text-gray-500 disabled:cursor-not-allowed"
            />

            @if(!empty($results) && !($registroId && $this->hasUnsavedChanges()))
                <div class="absolute z-50 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden">
                    @foreach($results as $r)
                        <button type="button" wire:click="requestSelectInmueble({{ $r['id'] }})"
                            class="w-full text-left px-4 py-3 hover:bg-gray-50">
                            <div class="font-semibold text-gray-900">{{ $r['label'] }}</div>
                            <div class="text-xs text-gray-500">Disponible</div>
                        </button>
                    @endforeach
                </div>
            @endif

            @if($registroId && $this->hasUnsavedChanges())
                <p class="text-xs text-amber-700 mt-2">
                    Tienes cambios sin guardar. Guarda o descarta para buscar otro inmueble.
                </p>
            @endif

            {{-- ✅ Mensaje cuando el inmueble buscado es un poder y se abre la cabeza automáticamente --}}
            @if($checkinMsg)
                <div class="mt-3 rounded-xl border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                    {{ $checkinMsg }}
                </div>
            @endif

            @if($checkinError)
                <div class="mt-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                    {{ $checkinError }}
                </div>
            @endif
        </div>
    </div>

    @if($registroId)
        {{-- Header --}}
        <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6 flex flex-wrap items-center justify-between gap-4">
            <div>
                <div class="text-sm text-gray-500">Inmueble</div>
                <div class="text-lg font-black text-gray-900">{{ $inmuebleLabel }}</div>
                <div class="text-sm mt-1 space-y-2">
                    <span class="inline-flex px-3 py-1 rounded-full border text-xs font-semibold">
                        Estado: {{ $estado }}
                    </span>

                    @if($grupoId)
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="inline-flex items-center px-3 py-1 rounded-full border border-gray-200 bg-gray-50 text-xs font-semibold text-gray-700">
                                Grupo (cabeza): <span class="ml-1 text-gray-900">{{ $miembros[0]['inmueble'] ?? '—' }}</span>
                            </span>

                            @if(!$isCabezaSeleccionada)
                                <span class="inline-flex items-center px-3 py-1 rounded-full border border-red-200 bg-red-50 text-xs font-semibold text-red-700">
                                    Estás viendo un PODER (control bloqueado)
                                </span>
                            @endif
                        </div>
                    @endif
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div class="text-right">
                    <div class="text-sm text-gray-500">Control</div>
                    <div class="text-lg font-black text-gray-900">
                        {{ $controlNumero ? "#{$controlNumero}" : "Sin control" }}
                    </div>
                </div>

                <button type="button" wire:click="requestClearSelection"
                    class="px-4 py-2 rounded-xl border border-gray-300 hover:bg-gray-50 font-semibold">
                    Cambiar inmueble
                </button>
                @if($isCabezaSeleccionada && count($miembros) > 1)
                  <button type="button"
                      wire:click="separarCabeza"
                      class="px-4 py-2 rounded-xl border border-amber-300 bg-amber-50 hover:bg-amber-100 font-semibold text-amber-900">
                      Separar cabeza
                  </button>
                @endif
            </div>
        </div>

        {{-- Representación / Poderes --}}
        <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-black text-gray-900">Representación / Poderes</h2>
                    <p class="text-sm text-gray-600 mt-1">
                        Cabeza + inmuebles representados. Aquí calcularemos quórum/coeficiente después.
                    </p>
                </div>

                <div class="text-right">
                    <div class="text-sm text-gray-500">Coeficiente total (grupo)</div>
                    <div class="text-lg font-black text-gray-900">
                        {{ is_null($coefTotal) ? '—' : number_format($coefTotal, 4, '.', '') }}
                    </div>

                    {{-- ✅ Conteo de poderes --}}
                    @if(!is_null($poderCount))
                        <div class="text-xs text-gray-500 mt-1">
                            Poderes: <span class="font-semibold text-gray-800">{{ $poderCount }}</span>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Buscar para agregar poder --}}
            <div class="mt-5 relative"
                 x-data="{ open: false }"
                 x-on:click.away="open = false"
                 x-on:keydown.escape.window="open = false">

                <label class="text-sm font-semibold text-gray-700">Agregar poder (buscar inmueble o propietario)</label>

                <input type="text"
                    wire:model.live="poderSearch"
                    x-on:focus="open = true"
                    x-on:input="open = true"
                    placeholder="Ej: 3504 o Juan Pérez"
                    class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-black/20" />

                {{-- Dropdown (con altura máxima + scroll interno) --}}
                @if(!empty($poderResults))
                    <div x-show="open"
                         x-transition
                         class="absolute z-40 mt-2 w-full bg-white border border-gray-200 rounded-xl shadow-lg overflow-hidden max-h-56 overflow-auto">
                        @foreach($poderResults as $r)
                            <button type="button"
                                wire:click="addPoder({{ $r['id'] }})"
                                x-on:click="open = false"
                                class="w-full text-left px-4 py-3 hover:bg-gray-50">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <div class="font-semibold text-gray-900">{{ $r['label'] }}</div>
                                        <div class="text-xs text-gray-500">{{ $r['propietario'] }}</div>
                                    </div>
                                    <div class="text-xs font-semibold text-gray-700">
                                        coef: {{ number_format($r['coef'], 4, '.', '') }}
                                    </div>
                                </div>
                            </button>
                        @endforeach
                    </div>
                @endif

                @if($poderError)
                    <p class="text-xs text-red-600 mt-2">{{ $poderError }}</p>
                @endif

                @if($poderMsg)
                    <p class="text-xs text-green-700 mt-2">{{ $poderMsg }}</p>
                @endif
            </div>

            {{-- Lista miembros --}}
            <div class="mt-5 border border-gray-200 rounded-2xl overflow-hidden">
                <div class="grid grid-cols-12 gap-3 px-4 py-3 bg-gray-50 text-xs font-semibold text-gray-600">
                    <div class="col-span-3">Inmueble</div>
                    <div class="col-span-6">Propietario</div>
                    <div class="col-span-2 text-right">Coef</div>
                    <div class="col-span-1 text-right">Acción</div>
                </div>

                @forelse($miembros as $m)
                    <div class="grid grid-cols-12 gap-3 px-4 py-3 border-t border-gray-200 items-center">
                        <div class="col-span-3">
                            <div class="font-semibold text-gray-900">
                                {{ $m['inmueble'] }}
                                @if($m['es_cabeza'])
                                    <span class="ml-2 text-xs px-2 py-0.5 rounded-full border border-gray-300">Cabeza</span>
                                @else
                                    <span class="ml-2 text-xs px-2 py-0.5 rounded-full border border-gray-200 text-gray-600">Poder</span>
                                @endif
                            </div>
                        </div>

                        <div class="col-span-6 text-sm text-gray-700">
                            {{ $m['propietario'] }}
                        </div>

                        <div class="col-span-2 text-right text-sm font-semibold text-gray-900">
                            {{ number_format($m['coeficiente'], 4, '.', '') }}
                        </div>

                        <div class="col-span-1 text-right">
                            @if(!$m['es_cabeza'])
                                <button type="button" wire:click="removePoder({{ $m['miembro_id'] }})"
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

        {{-- Control de votación (solo cabeza) --}}
        @if($isCabezaSeleccionada)
            <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-black text-gray-900">Control de votación</h2>
                        <p class="text-sm text-gray-600 mt-1">Ingresa el número del control físico y asígnalo al registro actual.</p>
                    </div>
                    <div class="text-right">
                        <div class="text-sm text-gray-500">Estado</div>
                        <div class="text-lg font-black text-gray-900">{{ $controlNumero ? "#{$controlNumero}" : "Sin control" }}</div>
                    </div>
                </div>

                {{-- ✅ Mensaje explícito: se asigna al grupo/cabeza --}}
                <div class="mt-3 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                    Asignando control al grupo (cabeza: <span class="font-semibold text-gray-900">{{ $miembros[0]['inmueble'] ?? $inmuebleLabel }}</span>)
                </div>

                <div class="mt-4 grid md:grid-cols-12 gap-3 items-end">
                    <div class="md:col-span-4">
                        <label class="text-sm font-semibold text-gray-700">Número de control</label>
                        <input type="text"
                            wire:model.defer="controlNumeroInput"
                            placeholder="Ej: 27"
                            class="mt-1 w-full rounded-xl border-gray-300 @if($controlError) border-red-500 @endif" />
                    </div>

                    <div class="md:col-span-3">
                        <button type="button" wire:click="assignControl"
                            class="w-full px-6 py-2 rounded-xl bg-black text-white font-semibold hover:bg-black/90">
                            Asignar control
                        </button>
                    </div>

                    <div class="md:col-span-5">
                        @if($controlMsg)
                            <p class="text-sm text-green-700 font-semibold">{{ $controlMsg }}</p>
                        @endif
                        @if($controlError)
                            <p class="text-sm text-red-600 font-semibold">{{ $controlError }}</p>
                        @endif
                        @if(!$controlMsg && !$controlError)
                            <p class="text-sm text-gray-600">
                                Nota: si el control ya está asignado a otro inmueble, el sistema lo bloqueará.
                            </p>
                        @endif
                    </div>
                </div>
            </div>
        @else
            {{-- Opcional: mensaje compacto cuando NO es cabeza --}}
            <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6">
                <h2 class="text-lg font-black text-gray-900">Control de votación</h2>
                <p class="text-sm text-gray-600 mt-1">
                    Este inmueble es un <span class="font-semibold">PODER</span>. El control se asigna únicamente desde la
                    <span class="font-semibold">cabeza del grupo</span>.
                </p>
            </div>
        @endif


        {{-- Asistente presente --}}
        <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6">
            <h2 class="text-lg font-black text-gray-900 mb-4">Asistente presente</h2>

            <div class="grid md:grid-cols-3 gap-4">
                <div>
                    <label class="text-sm font-semibold text-gray-700">Nombre (opcional)</label>
                    <input type="text" wire:model.defer="asistenteNombre" class="mt-1 w-full rounded-xl border-gray-300"
                        placeholder="Ej: Carlos Pérez" />
                </div>

                <div>
                    <label class="text-sm font-semibold text-gray-700">
                        Teléfono <span class="text-red-600">*</span>
                    </label>
                    <input type="text" wire:model.defer="asistenteTelefono" placeholder="Obligatorio"
                        class="mt-1 w-full rounded-xl border-gray-300 @if($errorTelefono) border-red-500 @endif" />
                    @if($errorTelefono)
                        <p class="text-xs text-red-600 mt-1">{{ $errorTelefono }}</p>
                    @endif
                </div>

                <div>
                    <label class="text-sm font-semibold text-gray-700">Correo (opcional)</label>
                    <input type="email" wire:model.defer="asistenteCorreo" class="mt-1 w-full rounded-xl border-gray-300"
                        placeholder="Ej: correo@dominio.com" />
                </div>
            </div>

            <div class="mt-5 flex flex-wrap items-center justify-between gap-3">
                <span class="text-sm text-gray-500">
                    * El teléfono es obligatorio para poder cerrar el check-in.
                </span>

                <button type="button" wire:click="saveAsistente"
                    class="px-6 py-2 rounded-xl bg-black text-white font-semibold hover:bg-black/90">
                    Guardar asistente
                </button>
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
    @endif

</div>
