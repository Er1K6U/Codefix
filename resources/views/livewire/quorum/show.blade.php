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
                <p class="mt-1 text-sm text-gray-600">
                    Quórum en tiempo real (coeficientes / 100%)
                </p>
            </div>

            @if($eventoImagen)
                <div class="shrink-0 w-20 h-20 rounded-2xl border border-gray-200 shadow bg-white/90 backdrop-blur overflow-hidden">
                    <img src="{{ asset('storage/' . $eventoImagen) }}" class="w-full h-full object-cover" alt="Evento">
                </div>
            @endif
        </div>

        {{-- KPIs --}}
        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-10">
            @foreach ([
                ['Quórum actual', $quorumActual, '#0F3D4C'],
                ['Máximo alcanzado', $quorumMax, '#2E2E2E'],
                ['Retirado', $quorumRetirado, '#d32f57']
            ] as [$label, $value, $color])
                <div class="bg-white/80 backdrop-blur-md rounded-2xl shadow-xl border border-gray-200 p-6">
                    <p class="text-xs font-semibold text-gray-600">{{ $label }}</p>
                    <p class="mt-2 text-3xl font-black" style="color: {{ $color }}">
                        {{ number_format($value, 2) }}%
                    </p>
                    @if($label === 'Retirado')
                        <p class="mt-1 text-[11px] text-gray-500">(MVP: Máximo − Actual)</p>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- Barra gigante --}}
        <div class="bg-white/85 backdrop-blur-md rounded-3xl shadow-2xl border border-gray-200 p-8">
            <div class="flex items-end justify-between gap-4">
                <div>
                    <p class="text-sm font-semibold text-gray-600">Progreso</p>
                    <p class="text-5xl font-black text-[#0F3D4C] leading-none">
                        {{ number_format($quorumActual, 2) }}%
                    </p>
                </div>

                <div class="text-right">
                    <p class="text-xs text-gray-500">Actualiza automático</p>
                    <p class="text-xs text-gray-500">cada 1.5s</p>
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

    {{-- MINI FEED “Bienvenido” (derecha, apilado, se desvanece) --}}
    @if(!empty($ultimosLlegados))
        <style>
            @keyframes quorumToastFade {
                0%   { opacity: 0; transform: translateX(8px) translateY(-2px); }
                10%  { opacity: 1; transform: translateX(0) translateY(0); }
                75%  { opacity: 1; transform: translateX(0) translateY(0); }
                100% { opacity: 0; transform: translateX(10px) translateY(2px); }
            }
            .quorum-toast {
                animation: quorumToastFade 10s ease-in-out forwards;
            }
        </style>

        <div class="fixed right-6 top-28 z-50 w-[320px] space-y-3">
            @foreach($ultimosLlegados as $idx => $item)
                <div class="quorum-toast bg-white/85 backdrop-blur-md border border-gray-200 shadow-xl rounded-2xl p-4">
                    <div class="flex items-start gap-3">
                        <div class="w-10 h-10 rounded-xl bg-gray-100 flex items-center justify-center border border-gray-200">
                            {{-- icono simple --}}
                            <svg viewBox="0 0 24 24" class="w-5 h-5 text-gray-700" fill="currentColor">
                                <path d="M12 12a4 4 0 1 0-4-4 4 4 0 0 0 4 4Zm0 2c-4 0-7 2-7 4v1h14v-1c0-2-3-4-7-4Z"/>
                            </svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-[11px] font-semibold text-gray-500">Bienvenido</p>
                            <p class="text-sm font-black text-[#2E2E2E] truncate">
                                Inmueble {{ $item['label'] ?? '—' }}
                            </p>
                        </div>
                        <div class="ml-auto text-[11px] text-gray-400 font-semibold">
                            #{{ $idx + 1 }}
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

</div>
