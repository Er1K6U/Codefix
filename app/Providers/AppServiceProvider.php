<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Event;
use Illuminate\Auth\Events\Attempting;
use Livewire\Livewire;
use App\Models\User;
use App\Support\EventContext;
use App\Domain\Event\Models\Evento;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(EventContext::class, function () {
            $evento = Evento::query()->where('is_active', true)->first();
            return new EventContext($evento);
        });
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
        */
        Livewire::setUpdateRoute(function ($handle) {
            return Route::post('/_lw/update', $handle)->name('livewire.update');
        });

        Livewire::setScriptRoute(function ($handle) {
            return Route::get('/_lw/livewire.js', $handle)->name('livewire.js');
        });

        /*
        |--------------------------------------------------------------------------
        | Gates - permisos del sistema (DEFINITIVOS)
        |--------------------------------------------------------------------------
        */

        // ===== EVENTOS =====
        Gate::define(
            'eventos.ver',
            fn(User $user) =>
            $user->hasAnyRole(['ADMIN', 'OPERADOR', 'CLIENTE'])
        );

        Gate::define(
            'eventos.crear',
            fn(User $user) =>
            $user->hasRole('ADMIN')
        );

        Gate::define(
            'eventos.editar',
            fn(User $user) =>
            $user->hasRole('ADMIN')
        );

        Gate::define(
            'eventos.eliminar',
            fn(User $user) =>
            $user->hasRole('ADMIN')
        );

        Gate::define(
            'eventos.activar_puesto',
            fn(User $user) =>
            $user->hasAnyRole(['ADMIN', 'OPERADOR', 'CLIENTE'])
        );

        // ===== CHECK-IN =====
        Gate::define(
            'checkin.usar',
            fn(User $user) =>
            $user->hasAnyRole(['ADMIN', 'OPERADOR'])
        );

        // ===== USUARIOS (ADMIN) =====
        Gate::define(
            'usuarios.ver',
            fn(User $user) =>
            $user->hasRole('ADMIN')
        );

        Gate::define(
            'usuarios.editar',
            fn(User $user) =>
            $user->hasRole('ADMIN')
        );

        /*
        |--------------------------------------------------------------------------
        | Bloquear login si el usuario está desactivado
        |--------------------------------------------------------------------------
        */
        Event::listen(Attempting::class, function (Attempting $event) {
            $email = $event->credentials['email'] ?? null;

            if (!$email) {
                return;
            }

            $user = User::where('email', $email)->first();

            if ($user && $user->activo === false) {
                throw new \Illuminate\Auth\AuthenticationException(
                    'Tu usuario está desactivado. Contacta al administrador.'
                );
            }
        });
    }
}
