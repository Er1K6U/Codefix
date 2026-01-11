<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Limpia cache de permisos (OBLIGATORIO)
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $guard = 'web';

        // Permisos mínimos del sistema
        $perms = [
            'usuarios.ver',
            'usuarios.editar',

            'eventos.ver',
            'eventos.crear',
            'eventos.editar',
            'eventos.activar_puesto',

            'checkin.usar',
        ];

        foreach ($perms as $perm) {
            Permission::firstOrCreate([
                'name' => $perm,
                'guard_name' => $guard,
            ]);
        }

        $admin = Role::firstOrCreate([
            'name' => 'ADMIN',
            'guard_name' => $guard,
        ]);

        $operador = Role::firstOrCreate([
            'name' => 'OPERADOR',
            'guard_name' => $guard,
        ]);

        $cliente = Role::firstOrCreate([
            'name' => 'CLIENTE',
            'guard_name' => $guard,
        ]);

        // ADMIN: todo el control
        $admin->syncPermissions($perms);

        // OPERADOR: solo operación
        $operador->syncPermissions([
            'eventos.ver',
            'eventos.activar_puesto',
            'checkin.usar',
        ]);

        // CLIENTE: por ahora sin permisos
        $cliente->syncPermissions([]);
    }
}
