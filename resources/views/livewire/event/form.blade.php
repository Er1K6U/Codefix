<div class="max-w-3xl mx-auto p-6">
    <div class="mb-6 flex items-center justify-between">
        <h1 class="text-2xl font-black text-[#2E2E2E]">
            {{ $idEvento ? 'Editar evento' : 'Nuevo evento' }}
        </h1>

        <a href="{{ route('eventos.index') }}" class="text-sm text-gray-600 hover:underline">
            ← Volver
        </a>
    </div>

    <div class="bg-white rounded-2xl shadow p-6 space-y-5">
        @if (session('ok'))
            <div class="rounded-lg bg-green-100 text-green-800 px-4 py-2">
                {{ session('ok') }}
            </div>
        @endif

        <div>
            <label class="text-sm font-semibold text-gray-700">Título</label>
            <input type="text" wire:model.live="titulo"
                class="mt-1 w-full rounded-xl border-gray-300 focus:ring-[#0F3D4C]">
            @error('titulo') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
        </div>

        <div>
            <label class="text-sm font-semibold text-gray-700">Descripción (interna)</label>
            <textarea wire:model.live="descripcion" rows="4"
                class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-[#0F3D4C]"
                placeholder="Notas para el equipo: logística, instrucciones, observaciones..."></textarea>
            @error('descripcion') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
        </div>

        {{-- Tipo de quórum --}}
        <div>
            <label class="text-sm font-semibold text-gray-700">Tipo de quórum</label>
            <div class="mt-2 flex gap-6">
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="radio" wire:model.live="tipoQuorum" value="coeficiente">
                    Coeficiente (%)
                </label>
                <label class="flex items-center gap-2 text-sm cursor-pointer">
                    <input type="radio" wire:model.live="tipoQuorum" value="nominal">
                    Nominal (por personas / cédulas)
                </label>
            </div>
            @error('tipoQuorum') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
        </div>

        {{-- Excel base: coeficiente --}}
        @if($tipoQuorum !== 'nominal')
        <div class="mt-6">
            <label class="block text-sm font-semibold text-gray-700 mb-1">
                Excel base del evento <span class="text-gray-400 font-normal">(coeficientes / padrón de inmuebles)</span>
            </label>

            <input type="file" wire:model="baseExcelFile" accept=".xlsx,.xls" class="block w-full text-sm text-gray-700
                       file:mr-4 file:py-2 file:px-4
                       file:rounded-xl file:border-0
                       file:bg-[#0F3D4C] file:text-white
                       hover:file:opacity-90 transition
                       rounded-xl border-gray-300" />

            @if($baseExcelPath)
                <p class="mt-1 text-xs text-gray-500">Archivo actual: {{ basename($baseExcelPath) }}</p>
            @endif

            @error('baseExcelFile')
                <p class="mt-2 text-sm text-red-600 font-semibold">{{ $message }}</p>
            @enderror
        </div>
        @endif

        {{-- Excel base: nominal --}}
        @if($tipoQuorum === 'nominal')
        <div class="mt-6">
            <label class="block text-sm font-semibold text-gray-700 mb-1">
                Excel padrón nominal <span class="text-gray-400 font-normal">(lista de personas / cédulas)</span>
            </label>

            <input type="file" wire:model="personasExcelFile" accept=".xlsx,.xls" class="block w-full text-sm text-gray-700
                       file:mr-4 file:py-2 file:px-4
                       file:rounded-xl file:border-0
                       file:bg-[#0F3D4C] file:text-white
                       hover:file:opacity-90 transition
                       rounded-xl border-gray-300" />

            @if($personasExcelPath)
                <p class="mt-1 text-xs text-gray-500">Archivo actual: {{ basename($personasExcelPath) }}</p>
            @endif

            @error('personasExcelFile')
                <p class="mt-2 text-sm text-red-600 font-semibold">{{ $message }}</p>
            @enderror
        </div>
        @endif

        {{-- ✅ Excel controles --}}
        <div class="mt-4">
            <label class="block text-sm font-semibold text-gray-700 mb-2">
                Excel de controles
            </label>

            <input type="file" wire:model="controlesExcelFile" accept=".xlsx,.xls" class="block w-full text-sm text-gray-700
                       file:mr-4 file:py-2 file:px-4
                       file:rounded-xl file:border-0
                       file:bg-[#0F3D4C] file:text-white
                       hover:file:opacity-90 transition
                       rounded-xl border-gray-300" />

            @error('controlesExcelFile')
                <p class="mt-2 text-sm text-red-600 font-semibold">{{ $message }}</p>
            @enderror
        </div>

        {{-- Imagen del evento --}}
        <div class="mt-6">
            <label class="block text-sm font-semibold text-gray-700 mb-2">
                Imagen del evento
            </label>

            <input type="file" wire:model="imagenFile" class="block w-full text-sm text-gray-700
                       file:mr-4 file:py-2 file:px-4
                       file:rounded-xl file:border-0
                       file:bg-[#0F3D4C] file:text-white
                       hover:file:opacity-90 transition
                       rounded-xl border-gray-300" />

            @error('imagenFile')
                <p class="mt-2 text-sm text-red-600 font-semibold">{{ $message }}</p>
            @enderror

            <div class="mt-4 flex items-center gap-4">
                {{-- Vista previa nueva --}}
                @if ($imagenFile)
                    <div class="w-24 h-24 rounded-xl overflow-hidden border bg-white shadow">
                        <img src="{{ $imagenFile->temporaryUrl() }}" class="w-full h-full object-cover">
                    </div>
                    <p class="text-sm text-gray-600">Vista previa (sin guardar aún)</p>

                    {{-- Imagen actual --}}
                @elseif ($imagenActual)
                    <div class="w-24 h-24 rounded-xl overflow-hidden border bg-white shadow">
                        <img src="{{ asset('storage/' . $imagenActual) }}" class="w-full h-full object-cover">
                    </div>
                    <p class="text-sm text-gray-600">Imagen actual</p>

                @else
                    <p class="text-sm text-gray-500">Este evento no tiene imagen.</p>
                @endif
            </div>
        </div>

        <div>
            <label class="text-sm font-semibold text-gray-700">Fecha inicio</label>
            <input type="date" wire:model.live="fecha_inicio"
                class="mt-1 w-full rounded-xl border-gray-300 focus:ring-[#0F3D4C]">
            @error('fecha_inicio') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-center justify-between pt-4">
            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" wire:model.live="activo">
                Activo
            </label>

            <button wire:click="save" class="bg-[#0F3D4C] text-white px-5 py-2 rounded-xl hover:opacity-90 transition">
                Guardar evento
            </button>
        </div>
    </div>
</div>