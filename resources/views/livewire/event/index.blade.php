<div class="max-w-7xl mx-auto p-6">
    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-black text-[#2E2E2E]">Eventos</h1>

            @if (session('ok'))
                <div class="mt-3 inline-flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-2 text-green-800">
                    <span class="h-2.5 w-2.5 rounded-full bg-green-500 animate-pulse"></span>
                    <span class="text-sm font-semibold">{{ session('ok') }}</span>
                </div>
            @endif

            {{-- ✅ Estado del puesto (PC) + botón quitar --}}
            <div class="mt-3 flex flex-wrap items-center gap-2">
                @if(!empty($currentEventTitle))
                    <div class="inline-flex items-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-sky-800">
                        <span class="h-2.5 w-2.5 rounded-full bg-sky-500 animate-pulse"></span>
                        <span class="text-sm font-semibold">
                            Evento activo en este puesto: <span class="font-black">{{ $currentEventTitle }}</span>
                        </span>
                    </div>

                    @can('eventos.editar')
                        <button
                            wire:click="clearActiveForThisStation"
                            wire:loading.attr="disabled"
                            wire:target="clearActiveForThisStation"
                            class="rounded-xl border border-rose-200 bg-rose-50 px-4 py-2 text-sm font-semibold text-rose-700 hover:bg-rose-100 transition disabled:opacity-60"
                            title="Remover evento activo del puesto"
                        >
                            Quitar evento del puesto
                        </button>
                    @endcan
                @else
                    <div class="inline-flex items-center gap-2 rounded-xl border border-gray-200 bg-gray-50 px-4 py-2 text-gray-700">
                        <span class="h-2.5 w-2.5 rounded-full bg-gray-400"></span>
                        <span class="text-sm font-semibold">
                            No hay evento activo en este puesto.
                        </span>
                    </div>
                @endif
            </div>
        </div>

        @can('eventos.crear')
            <a href="{{ route('eventos.crear') }}"
               class="rounded-xl bg-[#0F3D4C] px-4 py-2 font-semibold text-white hover:opacity-90 transition">
                + Nuevo evento
            </a>
        @endcan
    </div>

    <div class="mb-4">
        <input type="text" wire:model.live="buscar" placeholder="Buscar evento..."
               class="w-full max-w-md rounded-xl border-gray-300 focus:ring-2 focus:ring-[#0F3D4C]">
    </div>

    <div class="bg-white rounded-2xl shadow overflow-hidden">
        <table class="w-full text-sm">
            <thead class="bg-gray-100 text-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left">Título</th>
                    <th class="px-4 py-3 text-center">Fecha inicio</th>
                    <th class="px-4 py-3 text-center">Estado</th>
                    <th class="px-4 py-3 text-center">Acciones</th>
                </tr>
            </thead>

            <tbody>
                @forelse($eventos as $evento)
                    @php
                        $hoy = now()->startOfDay();
                        $fecha = $evento->fecha_inicio?->copy()->startOfDay();
                        $esHoy = $fecha?->equalTo($hoy) ?? false;
                        $esFuturo = $fecha?->greaterThan($hoy) ?? false;

                        $isActiveInThisStation = isset($activeEventId) && ((int)$activeEventId === (int)$evento->id);
                        $globalEnabled = (bool) $evento->activo;
                    @endphp

                    {{-- ✅ Resaltar fila si está activa en este puesto --}}
                    <tr
                        class="border-t {{ $isActiveInThisStation ? 'bg-[#0F3D4C]/[0.04]' : '' }}"
                        wire:key="evento-{{ $evento->id }}"
                    >
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl overflow-hidden border bg-white flex-shrink-0">
                                    @if(!empty($evento->imagen))
                                        <img src="{{ asset('storage/'.$evento->imagen) }}" class="w-full h-full object-cover" />
                                    @else
                                        <div class="w-full h-full bg-gray-100"></div>
                                    @endif
                                </div>

                                <div class="leading-tight">
                                    <div>{{ $evento->titulo }}</div>
                                    <div class="text-xs text-gray-500">{{ $evento->slug }}</div>
                                </div>
                            </div>
                        </td>

                        <td class="px-4 py-3 text-center">
                            {{ $evento->fecha_inicio->format('Y-m-d') }}
                        </td>

                        <td class="px-4 py-3 text-center">
                            <div class="inline-flex items-center justify-center gap-2 flex-wrap">
                                {{-- Badge Estado global --}}
                                @if($globalEnabled)
                                    <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold
                                                 border-emerald-200 bg-emerald-50 text-emerald-700 shadow-sm">
                                        <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                        Habilitado
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold
                                                 border-gray-200 bg-gray-50 text-gray-600 shadow-sm">
                                        <span class="h-2 w-2 rounded-full bg-gray-400"></span>
                                        Deshabilitado
                                    </span>
                                @endif

                                {{-- Badge Tiempo --}}
                                @if($esHoy)
                                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold
                                                 border-sky-200 bg-sky-50 text-sky-700 shadow-sm">
                                        Hoy
                                    </span>
                                @elseif($esFuturo)
                                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold
                                                 border-amber-200 bg-amber-50 text-amber-800 shadow-sm">
                                        Próximo
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full border px-3 py-1 text-xs font-semibold
                                                 border-rose-200 bg-rose-50 text-rose-700 shadow-sm">
                                        Pasado
                                    </span>
                                @endif

                                {{-- Badge "En este puesto" --}}
                                @if($isActiveInThisStation)
                                    <span class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold
                                                 border-[#0F3D4C]/20 bg-[#0F3D4C]/5 text-[#0F3D4C] shadow-sm">
                                        <span class="h-2 w-2 rounded-full bg-[#0F3D4C] animate-pulse"></span>
                                        En este puesto
                                    </span>
                                @endif
                            </div>
                        </td>

                        <td class="px-4 py-3 text-center">
                            @can('eventos.editar')
                                <div class="flex items-center justify-center gap-2 flex-wrap">

                                    <a href="{{ route('eventos.editar', $evento->id) }}"
                                       class="rounded-lg border px-3 py-1.5 text-sm hover:bg-gray-50 transition">
                                        Editar
                                    </a>

                                    {{-- ✅ Activar por puesto (PC) --}}
                                    <button
                                        wire:click.prevent="activateForThisStation({{ $evento->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="activateForThisStation({{ $evento->id }})"
                                        @disabled(!$globalEnabled)
                                        class="rounded-lg px-3 py-1.5 text-sm text-white hover:opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed
                                               {{ $isActiveInThisStation ? 'bg-[#0F3D4C]' : 'bg-[#4CAF50]' }}"
                                        title="{{ $globalEnabled ? '' : 'Este evento está deshabilitado globalmente' }}"
                                    >
                                        @if(!$globalEnabled)
                                            No habilitado
                                        @else
                                            {{ $isActiveInThisStation ? 'Activo en este puesto' : 'Activar en este puesto' }}
                                        @endif
                                    </button>

                                    {{-- Toggle global (habilitar/deshabilitar) --}}
                                    <button
                                        wire:click.prevent="toggleActivo({{ $evento->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="toggleActivo({{ $evento->id }})"
                                        class="rounded-lg px-3 py-1.5 text-sm text-white hover:opacity-90 transition disabled:opacity-60"
                                        style="background-color: {{ $globalEnabled ? '#2E2E2E' : '#7A7A7A' }};"
                                    >
                                        {{ $globalEnabled ? 'Deshabilitar' : 'Habilitar' }}
                                    </button>

                                </div>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="px-4 py-6 text-center text-gray-500">
                            No hay eventos registrados
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $eventos->links() }}
    </div>

    {{-- ✅ MODAL confirmación cambio de evento en este puesto --}}
    @if(!empty($confirmChange))
        <div class="fixed inset-0 z-50 flex items-center justify-center">
            <div class="absolute inset-0 bg-black/40"></div>

            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl p-6">
                <h3 class="text-lg font-black text-[#2E2E2E]">
                    Cambiar evento activo en este puesto
                </h3>

                <p class="mt-3 text-sm text-gray-700 leading-relaxed">
                    Este puesto ya tiene activo:
                    <span class="font-black">{{ $currentEventTitle }}</span>.
                    <br>
                    ¿Deseas cambiarlo por:
                    <span class="font-black">{{ $pendingEventTitle }}</span>?
                </p>

                <div class="mt-6 flex items-center justify-end gap-2">
                    <button
                        wire:click="cancelChange"
                        class="rounded-xl border px-4 py-2 text-sm font-semibold hover:bg-gray-50 transition"
                    >
                        Cancelar
                    </button>

                    <button
                        wire:click="confirmChangeEvent"
                        class="rounded-xl bg-[#0F3D4C] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 transition"
                    >
                        Sí, cambiar evento
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
