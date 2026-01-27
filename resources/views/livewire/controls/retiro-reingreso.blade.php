<div class="max-w-7xl mx-auto p-6 space-y-6">

    {{-- MODAL (éxito / error) --}}
    @if($modalOpen)
        <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 p-4" x-data
            x-init="$nextTick(() => $refs.okBtn?.focus())" @keydown.window.enter.prevent.stop="$wire.closeModal()"
            @keydown.window.escape.prevent.stop="$wire.closeModal()">
            <div class="w-full max-w-md rounded-3xl bg-white border border-gray-200 shadow-2xl p-7">
                <div class="flex items-start gap-4">
                    <div class="mt-1 w-12 h-12 rounded-2xl flex items-center justify-center
                                                                                                {{ $modalType === 'success' ? 'bg-emerald-50 border border-emerald-200' : ($modalType === 'info' ? 'bg-sky-50 border border-sky-200' : 'bg-red-50 border border-red-200') }}
                                                                                        @if($modalType === 'success')
                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                                            <svg class="
                                                                                            w-7 h-7 text-emerald-600" viewBox="0 0 24 24" fill="none">
                                                                                            <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
                                                                                                stroke-linejoin="round" />
                                                                                            </svg>
                                                                                        @elseif($modalType === 'info')
                            <svg class="w-7 h-7 text-sky-600" viewBox="0 0 24 24" fill="none">
                                <path d="M12 8h.01M11 12h1v4h1" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <path d="M12 22a10 10 0 1 0 0-20 10 10 0 0 0 0 20Z" stroke="currentColor" stroke-width="2.2" />
                            </svg>
                        @else
                            <svg class="w-7 h-7 text-red-600" viewBox="0 0 24 24" fill="none">
                                <path
                                    d="M12 9v4m0 4h.01M10.29 3.86l-7.4 13.2A2 2 0 0 0 4.64 20h14.72a2 2 0 0 0 1.75-2.94l-7.4-13.2a2 2 0 0 0-3.42 0Z"
                                    stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
                            </svg>
                        @endif
                    </div>

                    <div class="flex-1">
                        <h3 class="text-lg font-black text-gray-900">{{ $modalTitle }}</h3>
                        <p class="text-sm text-gray-600 mt-1">{{ $modalBody }}</p>

                        <div class="mt-5 flex justify-end">
                            <button type="button" x-ref="okBtn" wire:click="closeModal"
                                class="px-5 py-2 rounded-xl bg-black text-white font-semibold hover:bg-black/90 transition">
                                Entendido
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Header --}}
    <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6">
        <h1 class="text-2xl font-black text-gray-900">Retiro / Reingreso de controles</h1>
    </div>

    {{-- Dos acciones --}}
    <div class="grid lg:grid-cols-2 gap-6">

        {{-- Retirar --}}
        <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6 border-l-4 border-l-red-400">
            <h2 class="text-lg font-black text-gray-900">Retirar control</h2>
            <p class="text-sm text-gray-600 mt-1">
                Digita el número del control y presiona <span class="font-semibold">Enter</span>.
            </p>

            <div class="mt-4">
                <label class="text-sm font-semibold text-gray-700">Número de control</label>
                <input id="retiroNumero" type="text" wire:model.defer="retiroNumero"
                    wire:keydown.enter.prevent="retirar" placeholder="Ej: 27"
                    class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-red-200" />
                <div class="mt-2 text-xs text-gray-500">
                    Esto marcará el control como retirado y descontará el coeficiente del grupo.
                </div>
            </div>
        </div>

        {{-- Reingresar --}}
        <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6 border-l-4 border-l-emerald-400">
            <h2 class="text-lg font-black text-gray-900">Reingresar control</h2>
            <p class="text-sm text-gray-600 mt-1">
                Digita el número del control y presiona <span class="font-semibold">Enter</span>.
            </p>

            <div class="mt-4">
                <label class="text-sm font-semibold text-gray-700">Número de control</label>
                <input id="reingresoNumero" type="text" wire:model.defer="reingresoNumero"
                    wire:keydown.enter.prevent="reingresar" placeholder="Ej: 27"
                    class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-emerald-200" />
                <div class="mt-2 text-xs text-gray-500">
                    Esto vuelve a activar el control y suma nuevamente el coeficiente del grupo.
                </div>
            </div>
        </div>
    </div>
    {{-- Consultar control (sin lógica aún) --}}
    <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6 border-l-4 border-l-sky-400">
        <h2 class="text-lg font-black text-gray-900">Consultar control</h2>
        <p class="text-sm text-gray-600 mt-1">
            Digita el número del control para ver a qué inmueble corresponde y poder contactar al asistente.
        </p>

        <div class="mt-4">
            <label class="text-sm font-semibold text-gray-700">Número de control</label>
            <input id="consultaNumero" type="text" wire:model.defer="consultaNumero"
                wire:keydown.enter.prevent="consultarControl" placeholder="Ej: 27"
                class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-sky-200" />
            <div class="mt-2 text-xs text-gray-500">
                Presiona <span class="font-semibold">Enter</span> para consultar.
            </div>
        </div>

        @if($consultaReady)
            <div class="mt-4 rounded-2xl border border-sky-200 bg-sky-50 p-4">
                <div class="text-sm font-black text-gray-900">Resultado</div>

                <div class="mt-2 grid md:grid-cols-2 gap-3 text-sm text-gray-700">
                    <div><span class="font-semibold">Inmueble/Grupo:</span> {{ $consultaInmueble ?? '—' }}</div>
                    <div><span class="font-semibold">Propietario:</span> {{ $consultaPropietario ?? '—' }}</div>
                    <div><span class="font-semibold">Asistente:</span> {{ $consultaAsistente ?? '—' }}</div>
                    <div><span class="font-semibold">Celular:</span> {{ $consultaTelefono ?? '—' }}</div>
                    <div class="md:col-span-2 text-xs text-gray-600">
                        Estado registro: <span class="font-semibold">{{ $consultaEstadoRegistro ?? '—' }}</span>
                    </div>
                </div>
            </div>
        @endif
    </div>
    {{-- Reemplazar control (sin lógica aún) --}}
    <div class="bg-white border border-gray-200 rounded-2xl shadow-xl p-6 border-l-4 border-l-amber-400">
        <h2 class="text-lg font-black text-gray-900">Reemplazar control</h2>
        <p class="text-sm text-gray-600 mt-1">
            Úsalo si un control se entregó defectuoso (sin batería, dañado, etc.) y necesitas cambiarlo por otro
        </p>

        <div class="mt-4 grid lg:grid-cols-2 gap-4">
            <div>
                <label class="text-sm font-semibold text-gray-700">Control actual</label>
                <input id="reemplazoNumeroActual" type="text" wire:model.defer="reemplazoNumeroActual"
                    wire:keydown.enter.prevent="buscarControlActual" placeholder="Ej: 27"
                    class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-amber-200" />
                <div class="mt-2 text-xs text-gray-500">
                    Digita el número del control que ya está entregado.
                </div>
            </div>

            <div>
                <label class="text-sm font-semibold text-gray-700">Nuevo control</label>
                <input id="reemplazoNumeroNuevo" type="text" wire:model.defer="reemplazoNumeroNuevo"
                    wire:keydown.enter.prevent="buscarControlNuevo" placeholder="Ej: 105"
                    class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-amber-200" />
                <div class="mt-2 text-xs text-gray-500">
                    Digita el número del control que vas a entregar.
                </div>
            </div>
        </div>

        @if($reemplazoRegistroId)
            <div class="mt-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <div class="text-sm font-black text-gray-900">Control actual: información</div>
                <div class="mt-2 grid md:grid-cols-2 gap-3 text-sm text-gray-700">
                    <div><span class="font-semibold">Asistente:</span> {{ $reemplazoInfoNombre }}</div>
                    <div><span class="font-semibold">Teléfono:</span> {{ $reemplazoInfoTelefono }}</div>
                    <div><span class="font-semibold">Inmueble/Grupo:</span> {{ $reemplazoInfoInmueble }}</div>
                    <div><span class="font-semibold">Estado registro:</span> {{ $reemplazoInfoEstadoRegistro }}</div>
                    <div><span class="font-semibold">Estado control:</span> {{ $reemplazoInfoEstadoControl }}</div>
                    <div class="text-xs text-gray-600">
                        Ahora digita el <span class="font-semibold">nuevo control</span> y luego presiona <span
                            class="font-semibold">Reemplazar</span>.
                    </div>
                </div>
            </div>
        @endif

        @if($reemplazoNumeroNuevo)
            <div
                class="mt-3 rounded-2xl border p-4
                                        {{ $reemplazoNuevoOk ? 'border-emerald-200 bg-emerald-50' : 'border-gray-200 bg-gray-50' }}">
                <div class="text-sm font-black text-gray-900">Nuevo control: validación</div>

                <div class="mt-2 grid md:grid-cols-2 gap-3 text-sm text-gray-700">
                    <div><span class="font-semibold">Estado:</span> {{ $reemplazoNuevoEstadoControl ?? '—' }}</div>
                    <div><span class="font-semibold">Serial:</span> {{ $reemplazoNuevoSerial ?? '—' }}</div>

                    @if($reemplazoNuevoOk)
                        <div class="md:col-span-2 text-sm text-emerald-700 font-semibold">
                            ✅ Listo: puedes oprimir “Reemplazar”.
                        </div>
                    @else
                        <div class="md:col-span-2 text-xs text-gray-600">
                            Presiona <span class="font-semibold">Enter</span> para validar el nuevo control (debe estar LIBRE).
                        </div>
                    @endif
                </div>
            </div>
        @endif


        <div class="mt-5 flex justify-end">
            <button id="btnReemplazarControl" type="button" wire:click="reemplazarControl"
                class="px-5 py-2 rounded-xl bg-amber-600 text-white font-semibold hover:bg-amber-700 transition">
                Reemplazar
            </button>
        </div>
    </div>
</div>
<script>
    document.addEventListener('livewire:init', () => {
        Livewire.on('focus-field', (payload) => {
            const id = payload?.id;
            if (!id) return;
            setTimeout(() => document.getElementById(id)?.focus(), 50);
        });
    });
</script>