<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Laravel') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>

<body class="font-sans antialiased overflow-hidden">
    <div class="flex h-screen bg-gray-100" x-data="{ sidebarOpen: false }">

        {{-- Sidebar --}}
        @include('layouts.navigation')

        {{-- Mobile overlay --}}
        <div
            x-show="sidebarOpen"
            x-on:click="sidebarOpen = false"
            class="fixed inset-0 z-20 bg-black/40 lg:hidden"
            style="display:none"
        ></div>

        {{-- Main content area --}}
        <div class="flex flex-col flex-1 min-w-0 overflow-hidden">

            {{-- Mobile top bar --}}
            <div class="flex items-center gap-3 px-4 h-12 bg-white border-b border-gray-200 shadow-sm lg:hidden shrink-0">
                <button x-on:click="sidebarOpen = true" class="text-gray-500 hover:text-gray-800 transition">
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                <span class="text-sm font-bold text-gray-800">{{ config('app.name', 'Coefix') }}</span>
            </div>

            {{-- Optional page heading (legacy slot) --}}
            @isset($header)
                <header class="bg-white shadow-sm shrink-0">
                    <div class="px-4 py-4 sm:px-6">
                        {{ $header }}
                    </div>
                </header>
            @endisset

            {{-- Page content --}}
            <main class="flex-1 overflow-y-auto">
                {{ $slot }}
            </main>
        </div>
    </div>

    @livewireScripts(['update' => '/_lw/update'])
</body>

</html>
