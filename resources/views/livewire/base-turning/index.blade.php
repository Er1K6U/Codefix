<div class="max-w-7xl mx-auto p-6">
    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-black text-[#2E2E2E]">Base Turning</h1>
        </div>

        <div class="flex gap-2">
            <button wire:click="refreshRows"
                class="px-4 py-2 rounded-xl bg-[#0F3D4C] text-white font-semibold transition-all duration-300 hover:scale-[1.01] active:scale-[0.98] shadow">
                Actualizar
            </button>

            <button wire:click="copyAll"
                class="px-4 py-2 rounded-xl bg-[#4CAF50] text-white font-semibold transition-all duration-300 hover:scale-[1.01] active:scale-[0.98] shadow">
                Copiar
            </button>
        </div>
    </div>

    <div class="bg-white border border-gray-300 rounded-2xl shadow-2xl overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 border-b">
                    <tr class="text-left text-gray-700">
                        <th class="px-4 py-3 font-bold">Código</th>
                        <th class="px-4 py-3 font-bold"># Control</th>
                        <th class="px-4 py-3 font-bold">Inmueble cabeza</th>
                        <th class="px-4 py-3 font-bold">Propietario</th>
                        <th class="px-4 py-3 font-bold">Coef</th>
                        <th class="px-4 py-3 font-bold">Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $r)
                        <tr class="border-b last:border-b-0">
                            <td class="px-4 py-3 text-gray-800">{{ $r['codigo'] }}</td>
                            <td class="px-4 py-3 font-semibold text-gray-800">{{ $r['control_numero'] }}</td>
                            <td class="px-4 py-3 text-gray-800">{{ $r['inmueble'] }}</td>
                            <td class="px-4 py-3 text-gray-800">{{ $r['propietario'] }}</td>
                            <td class="px-4 py-3 font-mono text-gray-900">{{ $r['coef'] }}</td>
                            <td class="px-4 py-3">
                                <span
                                    class="px-2 py-1 rounded-lg text-xs font-bold
                                                                    {{ $r['estado'] === 'CHECKED_IN' ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                                    {{ $r['estado'] }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                No hay registros CHECKED_IN o RETIRADO en este evento.
                            </td>
                        </tr>
                    @endforelse
                    @if(count($rows))
                        <tr class="border-t bg-gray-50">
                            <td class="px-4 py-3 font-bold text-gray-900" colspan="4">TOTAL</td>
                            <td class="px-4 py-3 font-mono font-black text-gray-900">
                                {{ str_pad((string) ((int) round($totalCoef * 100)), 3, '0', STR_PAD_LEFT) }}
                            </td>
                            <td class="px-4 py-3"></td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    {{-- Toast simple --}}
    <div x-data="{ show:false, msg:@entangle('msg') }" x-on:bt-toast.window="show=true; setTimeout(()=>show=false,1200)"
        x-show="show" x-transition
        class="fixed bottom-6 right-6 bg-black text-white px-4 py-2 rounded-xl shadow-lg text-sm" style="display:none">
        <span x-text="msg"></span>
    </div>

    <script>
        window.addEventListener('bt-copy', async (e) => {
            try {
                await navigator.clipboard.writeText(e.detail.text || '');
            } catch (err) {
                // fallback (por si clipboard está bloqueado)
                const ta = document.createElement('textarea');
                ta.value = e.detail.text || '';
                document.body.appendChild(ta);
                ta.select();
                document.execCommand('copy');
                document.body.removeChild(ta);
            }
        });
    </script>
</div>