<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Gate;

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
