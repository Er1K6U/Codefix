<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        /*
        |--------------------------------------------------------------------------
        | Livewire fix for subfolder installs (/Coefix/public)
        |--------------------------------------------------------------------------
        | This forces Livewire to use internal routes instead of /livewire/update
        | which breaks when the app is not served from the server root.
        */
        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/_lw/update', $handle)->name('livewire.update');
        });

        Livewire::setScriptRoute(function ($handle) {
            return Route::get('/_lw/livewire.js', $handle)->name('livewire.js');
        });

        /*
        |--------------------------------------------------------------------------
        | Gates - permisos del sistema
        |--------------------------------------------------------------------------
        */
        Gate::define(
            'eventos.ver',
            fn($user) =>
            $user->hasAnyRole(['admin', 'registrador', 'consulta'])
        );

        Gate::define(
            'eventos.crear',
            fn($user) =>
            $user->hasAnyRole(['admin', 'registrador'])
        );

        Gate::define(
            'eventos.editar',
            fn($user) =>
            $user->hasAnyRole(['admin', 'registrador'])
        );

        Gate::define(
            'eventos.eliminar',
            fn($user) =>
            $user->hasRole('admin')
        );
    }
}
