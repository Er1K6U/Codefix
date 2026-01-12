<div class="max-w-7xl mx-auto p-6">
    <div class="flex items-start justify-between gap-4 mb-6">
        <div>
            <h1 class="text-2xl font-black text-[#2E2E2E]">Usuarios</h1>
            <p class="mt-1 text-sm text-gray-600">
                Administración de perfiles:
                <span class="font-semibold">ADMIN</span>,
                <span class="font-semibold">OPERADOR</span>,
                <span class="font-semibold">CLIENTE</span>.
            </p>

            @if (session('ok'))
                <div
                    class="mt-3 inline-flex items-center gap-2 rounded-xl border border-green-200 bg-green-50 px-4 py-2 text-green-800">
                    <span class="h-2.5 w-2.5 rounded-full bg-green-500 animate-pulse"></span>
                    <span class="text-sm font-semibold">{{ session('ok') }}</span>
                </div>
            @endif

            @if (session('warning'))
                <div
                    class="mt-3 inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-4 py-2 text-amber-800">
                    <span class="h-2.5 w-2.5 rounded-full bg-amber-500 animate-pulse"></span>
                    <span class="text-sm font-semibold">{{ session('warning') }}</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Buscador + Crear usuario --}}
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
        <input type="text" wire:model.live="buscar" placeholder="Buscar por nombre o correo..."
            class="w-full md:max-w-md rounded-xl border-gray-300 focus:ring-2 focus:ring-[#0F3D4C]">

        @can('usuarios.editar')
            <button wire:click="openCreateUser"
                class="rounded-xl bg-[#0F3D4C] px-4 py-2 font-semibold text-white hover:opacity-90 transition">
                + Crear usuario
            </button>
        @endcan
    </div>

    <div class="bg-white rounded-2xl shadow overflow-hidden border border-gray-200">
        <table class="w-full text-sm">
            <thead class="bg-gray-100 text-gray-700">
                <tr>
                    <th class="px-4 py-3 text-left">Usuario</th>
                    <th class="px-4 py-3 text-left">Correo</th>
                    <th class="px-4 py-3 text-center">Rol</th>
                    <th class="px-4 py-3 text-center">Email</th>
                    <th class="px-4 py-3 text-center">Estado</th>
                    <th class="px-4 py-3 text-center">Acciones</th>
                </tr>
            </thead>

            <tbody>
                @forelse($users as $u)
                    @php
                        $role = $u->roles->pluck('name')->first() ?? '—';
                        $isMe = (auth()->id() === $u->id);
                        $verified = !empty($u->email_verified_at);
                        $activo = (bool) ($u->activo ?? true);
                    @endphp

                    <tr class="border-t" wire:key="u-{{ $u->id }}">
                        {{-- Usuario --}}
                        <td class="px-4 py-3 font-medium">
                            <div class="flex items-center gap-2">
                                <span>{{ $u->name }}</span>

                                @if($isMe)
                                    <span
                                        class="text-xs font-semibold rounded-full border px-2 py-0.5 bg-sky-50 text-sky-700 border-sky-200">Tú</span>
                                @endif

                                @if(!$activo)
                                    <span
                                        class="text-xs font-semibold rounded-full border px-2 py-0.5 bg-gray-50 text-gray-600 border-gray-200">
                                        Desactivado
                                    </span>
                                @endif
                            </div>
                        </td>

                        {{-- Correo --}}
                        <td class="px-4 py-3 text-gray-700">{{ $u->email }}</td>

                        {{-- Rol (badge) --}}
                        <td class="px-4 py-3 text-center">
                            @if($role === 'ADMIN')
                                <span
                                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold border-[#0F3D4C]/20 bg-[#0F3D4C]/5 text-[#0F3D4C]">
                                    <span class="h-2 w-2 rounded-full bg-[#0F3D4C]"></span>
                                    ADMIN
                                </span>
                            @elseif($role === 'OPERADOR')
                                <span
                                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold border-emerald-200 bg-emerald-50 text-emerald-700">
                                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                    OPERADOR
                                </span>
                            @elseif($role === 'CLIENTE')
                                <span
                                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold border-amber-200 bg-amber-50 text-amber-800">
                                    <span class="h-2 w-2 rounded-full bg-amber-500"></span>
                                    CLIENTE
                                </span>
                            @else
                                <span
                                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold border-gray-200 bg-gray-50 text-gray-600">
                                    <span class="h-2 w-2 rounded-full bg-gray-400"></span>
                                    {{ $role }}
                                </span>
                            @endif
                        </td>

                        {{-- Email verificado --}}
                        <td class="px-4 py-3 text-center">
                            @if($verified)
                                <span
                                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold border-emerald-200 bg-emerald-50 text-emerald-700">
                                    <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                                    Verificado
                                </span>
                            @else
                                <span
                                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold border-gray-200 bg-gray-50 text-gray-600">
                                    <span class="h-2 w-2 rounded-full bg-gray-400"></span>
                                    Pendiente
                                </span>
                            @endif
                        </td>

                        {{-- Activo/Desactivado --}}
                        <td class="px-4 py-3 text-center">
                            @if($activo)
                                <span
                                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold border-emerald-200 bg-emerald-50 text-emerald-700">
                                    <span class="h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                                    Activo
                                </span>
                            @else
                                <span
                                    class="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-semibold border-gray-200 bg-gray-50 text-gray-600">
                                    <span class="h-2 w-2 rounded-full bg-gray-400"></span>
                                    Desactivado
                                </span>
                            @endif
                        </td>

                        {{-- Acciones --}}
                        <td class="px-4 py-3 text-center">
                            @can('usuarios.editar')
                                <div class="inline-flex flex-wrap items-center justify-center gap-2">
                                    {{-- Cambiar rol --}}
                                    <select class="rounded-xl border-gray-300 focus:ring-2 focus:ring-[#0F3D4C] text-sm"
                                        wire:change="requestRoleChange({{ $u->id }}, $event.target.value)">
                                        @foreach($roles as $r)
                                            <option value="{{ $r }}" @selected($role === $r)>{{ $r }}</option>
                                        @endforeach
                                    </select>

                                    {{-- ✅ Reset pass (NUEVO) --}}
                                    <button type="button" wire:click="requestResetPassword({{ $u->id }})"
                                        class="rounded-xl border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-800 hover:bg-gray-50 transition"
                                        title="Resetear contraseña">
                                        Reset pass
                                    </button>

                                    {{-- Activar / Desactivar --}}
                                    @if(!$isMe)
                                                    <button wire:click="requestToggleUser({{ $u->id }})"
                                                        class="rounded-xl border px-3 py-2 text-xs font-semibold transition
                                                                        {{ $activo
                                        ? 'border-rose-200 bg-rose-50 text-rose-700 hover:bg-rose-100'
                                        : 'border-emerald-200 bg-emerald-50 text-emerald-700 hover:bg-emerald-100' }}">
                                                        {{ $activo ? 'Desactivar' : 'Activar' }}
                                                    </button>
                                    @else
                                        <span class="text-xs text-gray-400">No puedes desactivarte</span>
                                    @endif
                                </div>
                            @else
                                <span class="text-xs text-gray-500">—</span>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-6 text-center text-gray-500">
                            No hay usuarios
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $users->links() }}
    </div>

    {{-- ✅ MODAL confirmación cambio de rol --}}
    @if(!empty($showRoleModal))
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" wire:click="cancelRoleChange"></div>

            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-gray-200 p-6">
                <div class="flex items-start gap-3">
                    <div
                        class="mt-1 flex h-10 w-10 items-center justify-center rounded-xl bg-amber-50 border border-amber-200">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-amber-700" fill="none"
                            viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v4m0 4h.01M10.29 3.86l-7.1 12.3A2 2 0 005 19h14a2 2 0 001.81-2.84l-7.1-12.3a2 2 0 00-3.42 0z" />
                        </svg>
                    </div>

                    <div class="flex-1">
                        <h3 class="text-lg font-black text-[#2E2E2E]">Confirmar cambio de rol</h3>

                        <p class="mt-2 text-sm text-gray-700 leading-relaxed">
                            Vas a cambiar el rol de:
                            <span class="font-black">{{ $targetUserName }}</span>
                            <span class="text-gray-500">({{ $targetUserEmail }})</span>
                        </p>

                        <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-700">
                            Rol actual: <span class="font-black">{{ $roleAntes }}</span><br>
                            Nuevo rol: <span class="font-black">{{ $roleDespues }}</span>
                        </div>

                        <div class="mt-6 flex items-center justify-end gap-2">
                            <button type="button" wire:click="cancelRoleChange"
                                class="rounded-xl border px-4 py-2 text-sm font-semibold hover:bg-gray-50 transition">
                                Cancelar
                            </button>

                            <button type="button" wire:click="applyRoleChange" wire:loading.attr="disabled"
                                wire:target="applyRoleChange"
                                class="rounded-xl bg-[#0F3D4C] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 transition disabled:opacity-60">
                                Sí, cambiar rol
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- ✅ MODAL Activar / Desactivar usuario --}}
    @if(!empty($showToggleModal))
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" wire:click="cancelToggleUser"></div>

            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-gray-200 p-6">
                <h3 class="text-lg font-black text-[#2E2E2E]">Confirmar acción</h3>

                <p class="mt-2 text-sm text-gray-700">
                    Vas a {{ $toggleTo ? 'ACTIVAR' : 'DESACTIVAR' }} el usuario:
                    <span class="font-black">{{ $toggleUserEmail }}</span>
                </p>

                <div class="mt-6 flex items-center justify-end gap-2">
                    <button type="button" wire:click="cancelToggleUser"
                        class="rounded-xl border px-4 py-2 text-sm font-semibold hover:bg-gray-50 transition">
                        Cancelar
                    </button>

                    <button type="button" wire:click="applyToggleUser" wire:loading.attr="disabled"
                        wire:target="applyToggleUser"
                        class="rounded-xl bg-[#0F3D4C] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 transition disabled:opacity-60">
                        Sí, confirmar
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ✅ MODAL Crear Usuario --}}
    @if(!empty($showCreateModal))
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" wire:click="cancelCreateUser"></div>

            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-gray-200 p-6">
                <h3 class="text-lg font-black text-[#2E2E2E]">Crear usuario</h3>
                <p class="mt-2 text-sm text-gray-600">Crea un usuario con contraseña temporal y rol.</p>

                <div class="mt-5 space-y-3">
                    <div>
                        <label class="text-sm font-semibold text-gray-700">Nombre</label>
                        <input type="text" wire:model.defer="newName"
                            class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-[#0F3D4C]">
                        @error('newName') <p class="mt-1 text-xs text-rose-600 font-semibold">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="text-sm font-semibold text-gray-700">Correo</label>
                        <input type="email" wire:model.defer="newEmail"
                            class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-[#0F3D4C]">
                        @error('newEmail') <p class="mt-1 text-xs text-rose-600 font-semibold">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
                        <div>
                            <label class="text-sm font-semibold text-gray-700">Rol</label>
                            <select wire:model.defer="newRole"
                                class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-[#0F3D4C]">
                                <option value="ADMIN">ADMIN</option>
                                <option value="OPERADOR">OPERADOR</option>
                                <option value="CLIENTE">CLIENTE</option>
                            </select>
                            @error('newRole') <p class="mt-1 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label class="text-sm font-semibold text-gray-700">Contraseña temporal</label>
                            <input type="text" wire:model.defer="newPassword"
                                class="mt-1 w-full rounded-xl border-gray-300 focus:ring-2 focus:ring-[#0F3D4C]">
                            @error('newPassword') <p class="mt-1 text-xs text-rose-600 font-semibold">{{ $message }}</p>
                            @enderror

                            <button type="button"
                                wire:click="$set('newPassword', '{{ \Illuminate\Support\Str::random(12) }}')"
                                class="mt-2 text-xs font-semibold text-sky-700 hover:underline">
                                Generar otra
                            </button>
                        </div>
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-end gap-2">
                    <button type="button" wire:click="cancelCreateUser"
                        class="rounded-xl border px-4 py-2 text-sm font-semibold hover:bg-gray-50 transition">
                        Cancelar
                    </button>

                    <button type="button" wire:click="createUser" wire:loading.attr="disabled" wire:target="createUser"
                        class="rounded-xl bg-[#0F3D4C] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 transition disabled:opacity-60">
                        Crear usuario
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- ✅ MODAL Reset Password (NUEVO) --}}
    @if(!empty($showResetModal))
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-black/40" wire:click="cancelResetPassword"></div>

            <div class="relative w-full max-w-lg rounded-2xl bg-white shadow-2xl border border-gray-200 p-6">
                <h3 class="text-lg font-black text-[#2E2E2E]">Resetear contraseña</h3>

                <p class="mt-2 text-sm text-gray-700 leading-relaxed">
                    Usuario:
                    <span class="font-black">{{ $resetUserName }}</span>
                    <span class="text-gray-500">({{ $resetUserEmail }})</span>
                </p>

                <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3">
                    <div class="text-xs font-semibold text-gray-600">Contraseña temporal</div>

                    <div class="mt-1 flex items-center gap-2">
                        <input type="text" readonly value="{{ $resetPassword }}"
                            class="w-full rounded-xl border-gray-300 bg-white focus:ring-2 focus:ring-[#0F3D4C]"
                            id="tempPassInput">

                        <button type="button"
                            onclick="navigator.clipboard?.writeText(document.getElementById('tempPassInput').value)"
                            class="rounded-xl bg-[#0F3D4C] px-3 py-2 text-xs font-semibold text-white hover:opacity-90 transition">
                            Copiar
                        </button>
                    </div>

                    <button type="button" wire:click="$set('resetPassword', '{{ \Illuminate\Support\Str::random(12) }}')"
                        class="mt-2 text-xs font-semibold text-sky-700 hover:underline">
                        Generar otra
                    </button>
                </div>

                <div class="mt-6 flex items-center justify-end gap-2">
                    <button type="button" wire:click="cancelResetPassword"
                        class="rounded-xl border px-4 py-2 text-sm font-semibold hover:bg-gray-50 transition">
                        Cerrar
                    </button>

                    <button type="button" wire:click="applyResetPassword" wire:loading.attr="disabled"
                        wire:target="applyResetPassword"
                        class="rounded-xl bg-[#0F3D4C] px-4 py-2 text-sm font-semibold text-white hover:opacity-90 transition disabled:opacity-60">
                        Sí, resetear
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>