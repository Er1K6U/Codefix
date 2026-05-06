<div class="relative min-h-[calc(100vh-80px)] overflow-hidden bg-gray-50" wire:poll.1500ms="refreshData">

    {{-- Fondo limpio con gradiente sutil --}}
    <div class="absolute inset-0 z-0">
        <div class="absolute inset-0 bg-gradient-to-b from-white via-white/70 to-gray-50"></div>
        <div class="absolute -top-40 -left-40 w-[520px] h-[520px] rounded-full blur-3xl opacity-20"
             style="background: radial-gradient(circle, rgba(15,61,76,0.35), transparent 60%);"></div>
        <div class="absolute -bottom-48 -right-48 w-[620px] h-[620px] rounded-full blur-3xl opacity-20"
             style="background: radial-gradient(circle, rgba(76,175,80,0.30), transparent 60%);"></div>
    </div>

    {{-- Contenido --}}
    <div class="relative z-10 max-w-7xl mx-auto px-6 py-8">

        {{-- Header --}}
        <div class="flex items-start justify-between gap-6 mb-10">
            <div>
                <h1 class="text-3xl md:text-4xl font-black text-[#2E2E2E]">
                    {{ $eventoTitulo }}
                </h1>
            </div>

            @if($eventoImagen)
                <div class="shrink-0 w-20 h-20 rounded-2xl border border-gray-200 shadow bg-white/90 backdrop-blur overflow-hidden">
                    <img src="{{ asset('storage/' . $eventoImagen) }}" class="w-full h-full object-cover" alt="Evento">
                </div>
            @endif
        </div>

        {{-- LAYOUT: izquierda KPIs+barra / derecha feed apilado --}}
        <div class="grid grid-cols-1 lg:grid-cols-[1fr_360px] gap-6">

            {{-- IZQUIERDA --}}
            <div class="space-y-6">

                {{-- KPIs --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    @php
                        if ($tipoQuorum === 'nominal') {
                            $kpis = [
                                ['Controles activos',  (int)($controlesActivos ?? 0),                   '#0F3D4C', 'count', null],
                                ['Máx. habilitadas',   ($personasCheckin + $personasRetiradas) . ' / ' . $personasTotal, '#2E2E2E', 'text',  null],
                                ['Retiradas',          $personasRetiradas,                              '#d32f57', 'count', 'Controles: ' . ($controlesRetiradosUnicos ?? 0)],
                            ];
                        } else {
                            $kpis = [
                                ['Controles activos',  (int)($controlesActivos ?? 0), '#0F3D4C', 'count', null],
                                ['Máximo alcanzado',   (float)$quorumMax,             '#2E2E2E', 'pct',   null],
                                ['Retirado',           (float)$quorumRetirado,        '#d32f57', 'pct',   'Controles: ' . ($controlesRetiradosUnicos ?? 0)],
                            ];
                        }
                    @endphp
                    @foreach($kpis as [$label, $value, $color, $type, $sub])
                        <div class="bg-white/80 backdrop-blur-md rounded-2xl shadow-xl border border-gray-200 p-6">
                            <p class="text-xs font-semibold text-gray-600">{{ $label }}</p>

                            <p class="mt-2 text-3xl font-black" style="color: {{ $color }}">
                                @if($type === 'pct')
                                    {{ number_format($value, 2) }}%
                                @elseif($type === 'text')
                                    {{ $value }}
                                @else
                                    {{ number_format($value, 0) }}
                                @endif
                            </p>

                            @if($sub)
                                <p class="mt-1 text-[12px] text-gray-600">
                                    {{ $sub }}
                                </p>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Barra gigante --}}
                <div class="bg-white/85 backdrop-blur-md rounded-3xl shadow-2xl border border-gray-200 p-8">
                    <div class="flex items-end justify-between gap-4">
                        <div>
                            <p class="text-sm font-semibold text-gray-600">Progreso</p>
                            @if($tipoQuorum === 'nominal')
                                <p class="text-5xl font-black text-[#0F3D4C] leading-none">
                                    {{ $personasCheckin }}
                                    <span class="text-2xl font-semibold text-gray-400">/ {{ $personasTotal }}</span>
                                </p>
                                <p class="mt-1 text-sm text-gray-500">
                                    personas &nbsp;·&nbsp;
                                    <span class="text-gray-400">{{ number_format((float)$quorumActual, 2) }}%</span>
                                </p>
                            @else
                                <p class="text-5xl font-black text-[#0F3D4C] leading-none">
                                    {{ number_format((float)$quorumActual, 2) }}%
                                </p>
                            @endif
                        </div>
                    </div>

                    @php
                        $pct = max(0, min(100, (float) $quorumActual));
                        $glow = 0.6 + ($pct / 100) * 0.4; // 0.6 -> 1.0
                    @endphp

                    <div class="mt-6">
                        <div class="h-7 rounded-full bg-[#E6E8EB] overflow-hidden border border-gray-300 shadow-inner">
                            <div class="h-full transition-all duration-700"
                                 style="
                                    width: {{ $pct }}%;
                                    background: linear-gradient(90deg, #0F3D4C 0%, #4CAF50 55%, #2E2E2E 100%);
                                    filter: saturate(1.05) brightness({{ $glow }});
                                 ">
                            </div>
                        </div>

                        <div class="mt-4 flex justify-between text-xs text-gray-600">
                            <span>0%</span>
                            <span>100%</span>
                        </div>
                    </div>
                </div>

            </div>

            {{-- DERECHA: Feed apilado (máx 10) --}}
            <div class="lg:pt-2">
                <div class="sticky top-28">
                    <div class="flex items-end justify-between mb-3">
                        <div>
                            <p class="text-xs font-semibold text-gray-600">Llegadas</p>
                            <p class="text-lg font-black text-[#2E2E2E] leading-tight">Bienvenido</p>
                        </div>
                    </div>

                    <style>
                        @keyframes quorumToastIn {
                            0% { opacity: 0; transform: translateX(10px) translateY(-4px); }
                            100% { opacity: 1; transform: translateX(0) translateY(0); }
                        }
                        .toast-in { animation: quorumToastIn .25s ease-out both; }

                        /* Fondo animado MUY sutil (corporativo) para la card #1 */
                        @keyframes softCorporateShift {
                            0%   { background-position: 0% 50%; }
                            50%  { background-position: 100% 50%; }
                            100% { background-position: 0% 50%; }
                        }
                        .toast-featured {
                            background: linear-gradient(120deg,
                                rgba(15,61,76,0.10),
                                rgba(76,175,80,0.10),
                                rgba(15,61,76,0.08)
                            );
                            background-size: 200% 200%;
                            animation: softCorporateShift 6s ease-in-out infinite;
                            border: 1px solid rgba(15,61,76,0.22);
                            box-shadow:
                                0 18px 35px rgba(0,0,0,0.10),
                                0 0 0 4px rgba(76,175,80,0.08);
                        }

                        .toast-normal {
                            background: rgba(255,255,255,0.85);
                            border: 1px solid rgba(229,231,235,1);
                            box-shadow: 0 12px 26px rgba(0,0,0,0.10);
                        }
                    </style>

                    <div class="space-y-3">
                        @forelse($ultimosLlegados as $idx => $item)
                            @php
                                $isNew = ($idx === 0);
                            @endphp

                            <div
                                class="{{ $isNew ? 'toast-in toast-featured' : 'toast-normal' }}
                                       backdrop-blur-md rounded-2xl p-4 transition-all duration-300"
                                style="{{ $isNew ? 'transform: scale(1.03);' : '' }}"
                                wire:key="quorum-toast-{{ $idx }}-{{ $item['label'] ?? 'x' }}"
                            >
                                <div class="flex items-start gap-3">
                                    <div class="{{ $isNew ? 'w-12 h-12' : 'w-11 h-11' }}
                                                rounded-2xl flex items-center justify-center border"
                                         style="{{ $isNew
                                            ? 'background: rgba(255,255,255,0.75); border-color: rgba(15,61,76,0.22);'
                                            : 'background: rgba(243,244,246,1); border-color: rgba(229,231,235,1);'
                                         }}"
                                    >
                                        <svg viewBox="0 0 24 24"
                                             class="{{ $isNew ? 'w-6 h-6' : 'w-5 h-5' }}"
                                             style="{{ $isNew ? 'color: #0F3D4C;' : 'color: #374151;' }}"
                                             fill="currentColor">
                                            <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4 0-7 2-7 4v1h14v-1c0-2-3-4-7-4Z"/>
                                        </svg>
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <p class="{{ $isNew ? 'text-base' : 'text-sm' }} font-black"
                                           style="color:#0F3D4C;">
                                            Bienvenido
                                        </p>

                                        <p class="{{ $isNew ? 'text-lg' : 'text-base' }} font-black text-[#2E2E2E] truncate">
                                            {{ $tipoQuorum === 'nominal' ? 'Persona' : 'Inmueble' }} {{ $item['label'] ?? '—' }}
                                        </p>

                                        @if($isNew)
                                            <p class="mt-1 text-[11px] font-semibold text-gray-600">
                                                Registro reciente
                                            </p>
                                        @endif
                                    </div>

                                    {{-- ❌ Quitamos #1 #2 etc. (no hay nada acá) --}}
                                </div>
                            </div>
                        @empty
                            <div class="bg-white/70 border border-gray-200 rounded-2xl p-4 text-sm text-gray-600">
                                Aún no hay llegadas registradas.
                            </div>
                        @endforelse
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
