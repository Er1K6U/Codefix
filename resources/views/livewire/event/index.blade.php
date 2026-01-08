<div class="max-w-7xl mx-auto p-6">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-black text-[#2E2E2E]">
            Eventos
        </h1>

        @can('eventos.crear')
            <a href="#" class="rounded-xl bg-[#0F3D4C] px-4 py-2 font-semibold text-white hover:opacity-90 transition">
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
                </tr>
            </thead>

            <tbody>
                @forelse($eventos as $evento)
                    <tr class="border-t">
                        <td class="px-4 py-3 font-medium">
                            {{ $evento->titulo }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            {{ $evento->fecha_inicio->format('Y-m-d') }}
                        </td>
                        <td class="px-4 py-3 text-center">
                            @if($evento->activo)
                                <span class="text-green-600 font-semibold">Activo</span>
                            @else
                                <span class="text-gray-400">Inactivo</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="3" class="px-4 py-6 text-center text-gray-500">
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
</div>