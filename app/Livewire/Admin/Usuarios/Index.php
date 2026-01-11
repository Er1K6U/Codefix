<?php

namespace App\Livewire\Admin\Usuarios;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\AuditLog;

class Index extends Component
{
    use WithPagination;

    public string $buscar = '';

    // Roles permitidos (hard rule)
    public array $roles = ['ADMIN', 'OPERADOR', 'CLIENTE'];

    // ✅ Modal confirmación cambio de rol (renombrado para NO chocar con método)
    public bool $showRoleModal = false;

    public ?int $targetUserId = null;
    public ?string $targetUserName = null;
    public ?string $targetUserEmail = null;
    public ?string $roleAntes = null;
    public ?string $roleDespues = null;

    // Modal crear usuario
    public bool $showCreateModal = false;

    public string $newName = '';
    public string $newEmail = '';
    public string $newRole = 'CLIENTE';
    public string $newPassword = '';

    public bool $showToggleModal = false;
    public ?int $toggleUserId = null;
    public ?string $toggleUserEmail = null;
    public ?bool $toggleTo = null;



    public function updatingBuscar(): void
    {
        $this->resetPage();
    }

    /**
     * Se dispara cuando cambias el select. NO cambia nada aún: abre modal.
     */
    public function requestRoleChange(int $userId, string $nuevoRol): void
    {
        Gate::authorize('usuarios.editar');

        if (!in_array($nuevoRol, $this->roles, true)) {
            session()->flash('warning', 'Rol inválido.');
            return;
        }

        $u = User::with('roles')->findOrFail($userId);

        $actual = $u->roles->pluck('name')->first();
        $actual = $actual ?: null;

        if ($actual === $nuevoRol) {
            session()->flash('ok', 'Este usuario ya tiene ese rol.');
            return;
        }

        // Regla dura: un admin NO puede quitarse su propio ADMIN
        if ($u->id === auth()->id() && $nuevoRol !== 'ADMIN') {
            session()->flash('warning', 'No puedes quitarte el rol ADMIN a ti mismo.');
            return;
        }

        // Regla dura: no dejar el sistema sin ADMIN
        if ($actual === 'ADMIN' && $nuevoRol !== 'ADMIN') {
            $adminsRestantes = User::role('ADMIN')->where('id', '!=', $u->id)->count();
            if ($adminsRestantes < 1) {
                session()->flash('warning', 'Debe existir al menos un usuario con rol ADMIN.');
                return;
            }
        }

        // Cargar info para modal
        $this->targetUserId = $u->id;
        $this->targetUserName = $u->name;
        $this->targetUserEmail = $u->email;
        $this->roleAntes = $actual ?? '—';
        $this->roleDespues = $nuevoRol;

        $this->showRoleModal = true;
    }

    public function cancelRoleChange(): void
    {
        $this->showRoleModal = false;

        $this->targetUserId = null;
        $this->targetUserName = null;
        $this->targetUserEmail = null;
        $this->roleAntes = null;
        $this->roleDespues = null;
    }

    public function openCreateUser(): void
    {
        Gate::authorize('usuarios.editar');

        $this->newName = '';
        $this->newEmail = '';
        $this->newRole = 'CLIENTE';
        $this->newPassword = $this->generateTempPassword();

        $this->showCreateModal = true;
    }

    public function cancelCreateUser(): void
    {
        $this->showCreateModal = false;
    }

    public function createUser(): void
    {
        Gate::authorize('usuarios.editar');

        $this->validate([
            'newName' => ['required', 'string', 'min:3', 'max:120'],
            'newEmail' => ['required', 'email', 'max:190', 'unique:users,email'],
            'newRole' => ['required', 'in:ADMIN,OPERADOR,CLIENTE'],
            'newPassword' => ['required', 'string', 'min:8', 'max:64'],
        ], [
            'newName.required' => 'El nombre es obligatorio.',
            'newEmail.unique' => 'Ese correo ya existe.',
            'newRole.in' => 'Rol inválido.',
            'newPassword.min' => 'La contraseña temporal debe tener mínimo 8 caracteres.',
        ]);

        // Regla dura: solo ADMIN puede crear ADMIN (por si algún día das usuarios.editar a otro rol)
        if ($this->newRole === 'ADMIN' && !auth()->user()->hasRole('ADMIN')) {
            abort(403);
        }

        DB::transaction(function () {
            $u = User::create([
                'name' => $this->newName,
                'email' => $this->newEmail,
                'password' => bcrypt($this->newPassword),
                'email_verified_at' => null,
            ]);

            $u->syncRoles([$this->newRole]);

            AuditLog::create([
                'modulo' => 'usuarios',
                'accion' => 'created',
                'subject_type' => User::class,
                'subject_id' => $u->id,
                'user_id' => auth()->id(),
                'meta' => [
                    'email' => $u->email,
                    'role' => $this->newRole,
                ],
                'ip' => request()->ip(),
                'user_agent' => request()->userAgent(),
            ]);
        });

        session()->flash('ok', 'Usuario creado correctamente.');
        $this->showCreateModal = false;

        // refrescar
        $this->resetPage();
    }

    private function generateTempPassword(): string
    {
        // 12 chars, legible, sin símbolos raros
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $pass = '';
        for ($i = 0; $i < 12; $i++) {
            $pass .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return $pass;
    }

    /**
     * ✅ Confirmación del modal: aquí sí cambiamos y auditamos.
     */
    public function applyRoleChange(): void
    {
        Gate::authorize('usuarios.editar');

        if (!$this->targetUserId || !$this->roleDespues) {
            $this->cancelRoleChange();
            return;
        }

        if (!in_array($this->roleDespues, $this->roles, true)) {
            session()->flash('warning', 'Rol inválido.');
            $this->cancelRoleChange();
            return;
        }

        $targetId = (int) $this->targetUserId;
        $nuevoRol = (string) $this->roleDespues;

        try {
            DB::transaction(function () use ($targetId, $nuevoRol) {
                $u = User::with('roles')->lockForUpdate()->findOrFail($targetId);

                $actual = $u->roles->pluck('name')->first();
                $actual = $actual ?: null;

                // Revalidaciones dentro de transacción
                if ($u->id === auth()->id() && $nuevoRol !== 'ADMIN') {
                    throw new \RuntimeException('No puedes quitarte el rol ADMIN a ti mismo.');
                }

                if ($actual === 'ADMIN' && $nuevoRol !== 'ADMIN') {
                    $adminsRestantes = User::role('ADMIN')->where('id', '!=', $u->id)->count();
                    if ($adminsRestantes < 1) {
                        throw new \RuntimeException('Debe existir al menos un usuario con rol ADMIN.');
                    }
                }

                $u->syncRoles([$nuevoRol]);

                AuditLog::create([
                    'modulo' => 'usuarios',
                    'accion' => 'role_changed',
                    'subject_type' => User::class,
                    'subject_id' => $u->id,
                    'user_id' => auth()->id(),
                    'meta' => [
                        'role_antes' => $actual,
                        'role_despues' => $nuevoRol,
                        'email' => $u->email,
                    ],
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            });
        } catch (\Throwable $e) {
            session()->flash('warning', $e->getMessage());
            $this->cancelRoleChange();
            return;
        }

        session()->flash('ok', 'Rol actualizado correctamente.');
        $this->cancelRoleChange();
        $this->resetPage();
    }

    public function requestToggleUser(int $userId): void
    {
        Gate::authorize('usuarios.editar');

        $u = User::findOrFail($userId);

        // Regla dura: no puedes desactivarte tú mismo
        if ($u->id === auth()->id()) {
            session()->flash('warning', 'No puedes desactivarte a ti mismo.');
            return;
        }

        $this->toggleUserId = $u->id;
        $this->toggleUserEmail = $u->email;
        $this->toggleTo = !$u->activo;

        $this->showToggleModal = true;
    }

    public function cancelToggleUser(): void
    {
        $this->showToggleModal = false;
        $this->toggleUserId = null;
        $this->toggleUserEmail = null;
        $this->toggleTo = null;
    }

    public function applyToggleUser(): void
    {
        Gate::authorize('usuarios.editar');

        if (!$this->toggleUserId || $this->toggleTo === null) {
            $this->cancelToggleUser();
            return;
        }

        $targetId = (int) $this->toggleUserId;
        $to = (bool) $this->toggleTo;

        try {
            DB::transaction(function () use ($targetId, $to) {
                $u = User::lockForUpdate()->findOrFail($targetId);

                if ($u->id === auth()->id()) {
                    throw new \RuntimeException('No puedes desactivarte a ti mismo.');
                }

                $antes = (bool) $u->activo;
                $u->activo = $to;
                $u->save();

                AuditLog::create([
                    'modulo' => 'usuarios',
                    'accion' => 'toggled_active',
                    'subject_type' => User::class,
                    'subject_id' => $u->id,
                    'user_id' => auth()->id(),
                    'meta' => [
                        'activo_antes' => $antes,
                        'activo_despues' => $to,
                        'email' => $u->email,
                    ],
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]);
            });
        } catch (\Throwable $e) {
            session()->flash('warning', $e->getMessage());
            $this->cancelToggleUser();
            return;
        }

        session()->flash('ok', $to ? 'Usuario activado.' : 'Usuario desactivado.');
        $this->cancelToggleUser();
        $this->resetPage();
    }


    public function render()
    {
        Gate::authorize('usuarios.ver');

        $users = User::query()
            ->when($this->buscar !== '', function ($q) {
                $term = '%' . $this->buscar . '%';
                $q->where(function ($qq) use ($term) {
                    $qq->where('name', 'like', $term)
                        ->orWhere('email', 'like', $term);
                });
            })
            ->with('roles')
            ->orderBy('name')
            ->paginate(12);

        return view('livewire.admin.usuarios.index', [
            'users' => $users,
            'roles' => $this->roles,
        ])->layout('layouts.app');
    }
}
