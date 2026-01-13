<div class="max-w-7xl mx-auto p-6">
    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-black text-[#2E2E2E]">Eventos</h1>

            @if (session('warning'))
                <div
                    x-data="{ open: true }"
                    x-show="open"
                    x-cloak
                    class="fixed inset-0 z-50 flex items-center justify-center p-4"
                    aria-modal="true"
                    role="dialog"
                >
                    <div class="absolute inset-0 bg-black/50" @click="open = false"></div>

                    <div
                        class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-gray-200 p-6"
                        x-transition
                    >
                        <div class="flex items-start gap-3">
                            <div class="mt-1 flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 border border-amber-200">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-amber-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                          d="M12 9v4m0 4h.01M10.29 3.86l-7.1 12.3A2 2 0 005 19h14a2 2 0 001.81-2.84l-7.1-12.3a2 2 0 00-3.42 0z"/>
                                </svg>
                            </div>

                            <div class="flex-1">
                                <h3 class="text-lg font-black text-[#2E2E2E]">Acción requerida</h3>
                                <p class="mt-2 text-sm text-gray-700 leading-relaxed">
                                    {{ session('warning') }}
                                </p>

                                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                                    Selecciona un evento y haz clic en <span class="font-bold">“Activar en este puesto”</span> para poder continuar al check-in.
                                </div>

                                <div class="mt-6 flex items-center justify-end gap-2">
                                    <button
                                        type="button"
                                        @click="open = false"
                                        class="rounded-xl border px-4 py-2 text-sm font-semibold hover:bg-gray-50 transition"
                                    >
                                        Entendido
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            @if (session('ok'))
                <div class="mt-3 inline-flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-2 text-green-800">
                    <span class="h-2.5 w-2.5 rounded-full bg-green-500 animate-pulse"></span>
                    <span class="text-sm font-semibold">{{ session('ok') }}</span>
                </div>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-2">
                @if(!empty($currentEventTitle))
                    <div class="inline-flex items-center gap-2 rounded-xl border border-sky-200 bg-sky-50 px-4 py-2 text-sky-800">
                        <span class="h-2.5 w-2.5 rounded-full bg-sky-500 animate-pulse"></span>
                        <span class="text-sm font-semibold">
                            Evento activo en este puesto: <span class="font-black">{{ $currentEventTitle }}</span>
                        </span>
                    </div>

                    @can('eventos.activar_puesto')
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

        {{-- ✅ Acciones (modo evento único) --}}
        <div class="flex items-center gap-2">
            {{-- 💾 Backup SQL (solo ADMIN) --}}
            @can('eventos.editar')
                <button
                    wire:click="backupEvento"
                    wire:loading.attr="disabled"
                    wire:target="backupEvento"
                    class="rounded-xl border border-[#0F3D4C]/20 bg-white px-4 py-2 font-semibold text-[#0F3D4C] hover:bg-[#0F3D4C]/5 transition disabled:opacity-60"
                    title="Generar backup SQL en storage/app/backups"
                >
                    <span wire:loading.remove wire:target="backupEvento">💾 Backup SQL</span>
                    <span wire:loading wire:target="backupEvento">Generando…</span>
                </button>
            @endcan

            {{-- + Nuevo evento (solo si NO hay eventos) --}}
            @can('eventos.crear')
                @if(\App\Domain\Event\Models\Evento::count() === 0)
                    <a href="{{ route('eventos.crear') }}"
                       class="rounded-xl bg-[#0F3D4C] px-4 py-2 font-semibold text-white hover:opacity-90 transition">
                        + Nuevo evento
                    </a>
                @endif
            @endcan
        </div>
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

                        $isActiveInThisStation = isset($activeEventId) && ((int) $activeEventId === (int) $evento->id);
                        $globalEnabled = (bool) $evento->activo;
                    @endphp

                    <tr class="border-t {{ $isActiveInThisStation ? 'bg-[#0F3D4C]/[0.04]' : '' }}"
                        wire:key="evento-{{ $evento->id }}">
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl overflow-hidden border bg-white flex-shrink-0">
                                    @if(!empty($evento->imagen))
                                        <img src="{{ asset('storage/' . $evento->imagen) }}" class="w-full h-full object-cover" />
                                    @else
                                        <div class="w-full h-full bg-gray-100"></div>
                                    @endif
                                </div>

                                <div class="leading-tight">
                                    <div>{{ $evento->titulo }}</div>
                                    {{-- slug oculto: ya no lo usamos en UI --}}
                                </div>
                            </div>
                        </td>

                        <td class="px-4 py-3 text-center">
                            {{ $evento->fecha_inicio->format('Y-m-d') }}
                        </td>

                        <td class="px-4 py-3 text-center">
                            <div class="inline-flex items-center justify-center gap-2 flex-wrap">
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
                            <div class="flex items-center justify-center gap-2 flex-wrap">

                                @can('eventos.activar_puesto')
                                    <button
                                        wire:click.prevent="activateForThisStation({{ $evento->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="activateForThisStation({{ $evento->id }})"
                                        @disabled(!$globalEnabled || $isActiveInThisStation)
                                        class="rounded-lg px-3 py-1.5 text-sm text-white hover:opacity-90 transition disabled:opacity-50 disabled:cursor-not-allowed
                                               {{ $isActiveInThisStation ? 'bg-[#0F3D4C]' : 'bg-[#4CAF50]' }}"
                                        title="{{ !$globalEnabled ? 'Este evento está deshabilitado globalmente' : ($isActiveInThisStation ? 'Ya está activo en este puesto' : '') }}">
                                        @if(!$globalEnabled)
                                            No habilitado
                                        @else
                                            {{ $isActiveInThisStation ? 'Activo en este puesto' : 'Activar en este puesto' }}
                                        @endif
                                    </button>
                                @endcan

                                @can('eventos.editar')
                                    <a href="{{ route('eventos.editar', $evento->id) }}"
                                        class="rounded-lg border px-3 py-1.5 text-sm hover:bg-gray-50 transition">
                                        Editar
                                    </a>

                                    <button
                                        wire:click.prevent="toggleActivo({{ $evento->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="toggleActivo({{ $evento->id }})"
                                        class="rounded-lg px-3 py-1.5 text-sm text-white hover:opacity-90 transition disabled:opacity-60"
                                        style="background-color: {{ $globalEnabled ? '#2E2E2E' : '#7A7A7A' }};">
                                        {{ $globalEnabled ? 'Deshabilitar' : 'Habilitar' }}
                                    </button>
                                @endcan
                                {{-- 🧹 Admin-only: Eliminar evento (borrado total) --}}
                                <button
                                    wire:click.prevent="requestDeleteEvent({{ $evento->id }})"
                                    class="rounded-lg px-3 py-1.5 text-sm text-white bg-rose-600 hover:bg-rose-700 transition"
                                >
                                    Eliminar
                                </button>

                            </div>
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
                    <button wire:click="cancelChange"
                        class="rounded-xl border px-4 py-2 text-sm font-semibold hover:bg-gray-50 transition">
                        Cancelar
                    </button>

                    <button wire:click="confirmChangeEvent"
                        class="rounded-xl bg-[#0F3D4C] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 transition">
                        Sí, cambiar evento
                    </button>
                </div>
            </div>
        </div>
    @endif
    {{-- 🧹 MODAL confirmación eliminar evento --}}
    @if(!empty($confirmDelete))
        <div class="fixed inset-0 z-50 flex items-center justify-center">
            <div class="absolute inset-0 bg-black/40"></div>

            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl p-6">
                <h3 class="text-lg font-black text-[#2E2E2E]">
                    Eliminar evento (borrado total)
                </h3>

                <p class="mt-3 text-sm text-gray-700 leading-relaxed">
                    Vas a eliminar el evento:
                    <span class="font-black">{{ $deleteEventTitle }}</span>
                    <br><br>
                    Esto borrará también:
                    <span class="font-semibold">padrón (base), controles, check-in y representación</span>.
                    <br>
                    Esta acción <span class="font-black text-rose-700">NO</span> se puede deshacer.
                </p>

                <div class="mt-6 flex items-center justify-end gap-2">
                    <button
                        wire:click="cancelDelete"
                        class="rounded-xl border px-4 py-2 text-sm font-semibold hover:bg-gray-50 transition"
                    >
                        Cancelar
                    </button>

                    <button
                        wire:click="confirmDeleteEvent"
                        class="rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white hover:bg-rose-700 transition"
                    >
                        Sí, eliminar todo
                    </button>
                </div>
            </div>
        </div>
    @endif
    {{-- 💾 MODAL: Generando backup (loading) --}}
    <div
        wire:loading.flex
        wire:target="backupEvento"
        class="fixed inset-0 z-50 items-center justify-center p-4"
        aria-modal="true"
        role="dialog"
    >
        <div class="absolute inset-0 bg-black/40"></div>

        <div class="relative w-full max-w-md rounded-2xl bg-white shadow-2xl border border-gray-200 p-6">
            <div class="flex items-start gap-3">
                <div class="mt-1 flex h-10 w-10 items-center justify-center rounded-xl bg-[#0F3D4C]/10 border border-[#0F3D4C]/20">
                    <svg class="h-6 w-6 text-[#0F3D4C] animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
                    </svg>
                </div>

                <div class="flex-1">
                    <h3 class="text-lg font-black text-[#2E2E2E]">Generando backup…</h3>
                    <p class="mt-2 text-sm text-gray-700 leading-relaxed">
                        Esto crea un archivo SQL en <span class="font-semibold">storage/app/backups</span>.
                    </p>

                    <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                        No cierres esta pestaña mientras termina.
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- 💾 MODAL: Resultado backup --}}
    @if(!empty($showBackupResult))
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" aria-modal="true" role="dialog">
            <div class="absolute inset-0 bg-black/40" wire:click="closeBackupResult"></div>

            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-gray-200 p-6">
                <div class="flex items-start gap-3">
                    <div class="mt-1 flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-50 border border-emerald-200">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-emerald-700" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                  d="M5 13l4 4L19 7" />
                        </svg>
                    </div>

                    <div class="flex-1">
                        <h3 class="text-lg font-black text-[#2E2E2E]">Backup listo</h3>

                        <p class="mt-2 text-sm text-gray-700 leading-relaxed">
                            {{ $backupResultMsg }}
                        </p>

                        <div class="mt-6 flex items-center justify-end gap-2">
                            <button
                                type="button"
                                wire:click="closeBackupResult"
                                class="rounded-xl bg-[#0F3D4C] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 transition"
                            >
                                Entendido
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
