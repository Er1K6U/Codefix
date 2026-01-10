<?php

use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

use App\Livewire\Event\Index as EventIndex;
use App\Livewire\Event\Form as EventForm;
use App\Livewire\Checkin\RegistroPantalla;


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

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('/eventos', EventIndex::class)->name('eventos.index');
    Route::get('/eventos/crear', EventForm::class)->name('eventos.crear');
    Route::get('/eventos/{id}/editar', EventForm::class)->name('eventos.editar');
    Route::middleware(['auth'])->group(function () {
        Route::get('/checkin', RegistroPantalla::class)->name('checkin');
    });

});

require __DIR__ . '/auth.php';
