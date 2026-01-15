<div class="max-w-7xl mx-auto p-6 space-y-6">

    {{-- MODAL (éxito / error) --}}
    @if($modalOpen)
        <div class="fixed inset-0 z-[9999] flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-3xl bg-white border border-gray-200 shadow-2xl p-7">
                <div class="flex items-start gap-4">
                    <div
                        class="mt-1 w-12 h-12 rounded-2xl flex items-center justify-center
                            {{ $modalType === 'ok' ? 'bg-emerald-50 border border-emerald-200' : 'bg-red-50 border border-red-200' }}">
                        @if($modalType === 'ok')
                            <svg class="w-7 h-7 text-emerald-600" viewBox="0 0 24 24" fill="none">
                                <path d="M20 6L9 17l-5-5" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"
                                    stroke-linejoin="round" />
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
                            <button type="button" wire:click="closeModal"
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
        <p class="text-sm text-gray-600 mt-1">
            Retira un control para descontar quórum, o reingrésalo para volver a sumar el coeficiente (incluye poderes
            del grupo).
        </p>
        <div class="mt-3 text-xs text-gray-500">
            Evento activo (contexto del puesto): <span
                class="font-semibold text-gray-800">{{ $eventoTitulo ?? '—' }}</span>
        </div>
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
                <input type="text" wire:model.defer="retiroNumero" wire:keydown.enter.prevent="retirar"
                    placeholder="Ej: 27"
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
                <input type="text" wire:model.defer="reingresoNumero" wire:keydown.enter.prevent="reingresar"
                    placeholder="Ej: 27"
                    class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-emerald-200" />
                <div class="mt-2 text-xs text-gray-500">
                    Esto vuelve a activar el control y suma nuevamente el coeficiente del grupo.
                </div>
            </div>
        </div>

    </div>

</div>