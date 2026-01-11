<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

use App\Livewire\Event\Index as EventIndex;
use App\Livewire\Event\Form as EventForm;
use App\Livewire\Checkin\RegistroPantalla;

// ✅ Admin Usuarios
use App\Livewire\Admin\Usuarios\Index as AdminUsuariosIndex;

/*
|--------------------------------------------------------------------------
| Livewire routes fix for subfolder installs
|--------------------------------------------------------------------------
| When the app lives under /Coefix/public, Livewire's default /livewire/update
| hits the server root and returns 404. We remap Livewire endpoints to /_lw/*
| so requests stay inside the app subfolder.
*/
Livewire::setUpdateRoute(function ($handle) {
    return Route::post('/_lw/update', $handle);
});

Livewire::setScriptRoute(function ($handle) {
    return Route::get('/_lw/livewire.js', $handle);
});

/*
|--------------------------------------------------------------------------
| Home (single entry point)
|--------------------------------------------------------------------------
| - Guest  -> login
| - Auth   -> Eventos (antes de check-in)
*/
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('eventos.index')
        : redirect()->route('login');
})->name('home');

/*
|--------------------------------------------------------------------------
| Authenticated area
|--------------------------------------------------------------------------
| Nota: 'usuario.activo' aquí bloquea TODO si el usuario está desactivado,
| incluyendo admin, eventos, check-in, etc. (lo que queremos).
*/
Route::middleware(['auth', 'verified', 'usuario.activo'])->group(function () {

    // Perfil
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Eventos
    Route::get('/eventos', EventIndex::class)->name('eventos.index');
    Route::get('/eventos/crear', EventForm::class)->name('eventos.crear');
    Route::get('/eventos/{id}/editar', EventForm::class)->name('eventos.editar');

    // Check-in (requiere evento activo)
    Route::get('/checkin', RegistroPantalla::class)
        ->middleware(['evento.activo'])
        ->name('checkin');

    /*
    |----------------------------------------------------------------------
    | Admin · Usuarios (SOLO ADMIN)
    |----------------------------------------------------------------------
    | Usamos middleware de Spatie: permission:usuarios.ver
    */
    Route::get('/admin/usuarios', AdminUsuariosIndex::class)
        ->middleware(['permission:usuarios.ver'])
        ->name('admin.usuarios');
});

// Auth routes (login, register, etc.)
require __DIR__ . '/auth.php';
