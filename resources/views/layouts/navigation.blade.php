<aside
    class="fixed inset-y-0 left-0 z-30 w-60 flex flex-col bg-[#0F3D4C]
           transition-transform duration-200 ease-in-out
           lg:static lg:inset-auto lg:z-auto lg:shrink-0 lg:transform-none"
    :class="{ '-translate-x-full': !sidebarOpen }"
>
    {{-- Logo --}}
    <div class="flex items-center gap-3 px-5 py-4 border-b border-white/10 shrink-0">
        <a href="{{ route('eventos.index') }}" class="flex items-center gap-3 min-w-0"
           x-on:click="sidebarOpen = false">
            <x-application-logo class="block h-7 w-auto fill-current text-white shrink-0" />
            <span class="text-white font-black text-base truncate">{{ config('app.name', 'Coefix') }}</span>
        </a>
    </div>

    {{-- Nav links --}}
    <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1">

        @role('ADMIN')
        @php
            $linksAdmin = [
                [
                    'label'  => 'Eventos',
                    'href'   => route('eventos.index'),
                    'active' => request()->routeIs('eventos.*'),
                    'icon'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
                ],
                [
                    'label'  => 'Usuarios',
                    'href'   => route('admin.usuarios'),
                    'active' => request()->routeIs('admin.usuarios'),
                    'icon'   => '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
                ],
                [
                    'label'  => 'Check-in',
                    'href'   => route('checkin'),
                    'active' => request()->routeIs('checkin'),
                    'icon'   => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
                ],
                [
                    'label'  => 'Quórum',
                    'href'   => url('/quorum'),
                    'active' => request()->is('quorum'),
                    'icon'   => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
                ],
                [
                    'label'  => 'Base Turning',
                    'href'   => route('base-turning.index'),
                    'active' => request()->routeIs('base-turning.index'),
                    'icon'   => '<line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/>',
                ],
                [
                    'label'  => 'Informes',
                    'href'   => route('informes.excel'),
                    'active' => false,
                    'icon'   => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/>',
                ],
                [
                    'label'  => 'Retiro/Controles',
                    'href'   => url('/controles/retiro'),
                    'active' => request()->is('controles/retiro'),
                    'icon'   => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
                ],
            ];
        @endphp
        @foreach($linksAdmin as $item)
            <a href="{{ $item['href'] }}"
               x-on:click="sidebarOpen = false"
               class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition-colors
                      {{ $item['active']
                          ? 'bg-white/15 text-white'
                          : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.8">{!! $item['icon'] !!}</svg>
                {{ $item['label'] }}
            </a>
        @endforeach
        @endrole

        @role('OPERADOR')
        @php
            $linksOperador = [
                [
                    'label'  => 'Eventos',
                    'href'   => route('eventos.index'),
                    'active' => request()->routeIs('eventos.*'),
                    'icon'   => '<rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>',
                ],
                [
                    'label'  => 'Check-in',
                    'href'   => route('checkin'),
                    'active' => request()->routeIs('checkin'),
                    'icon'   => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
                ],
                [
                    'label'  => 'Quórum',
                    'href'   => url('/quorum'),
                    'active' => request()->is('quorum'),
                    'icon'   => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
                ],
                [
                    'label'  => 'Retiro/Controles',
                    'href'   => url('/controles/retiro'),
                    'active' => request()->is('controles/retiro'),
                    'icon'   => '<polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
                ],
            ];
        @endphp
        @foreach($linksOperador as $item)
            <a href="{{ $item['href'] }}"
               x-on:click="sidebarOpen = false"
               class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition-colors
                      {{ $item['active']
                          ? 'bg-white/15 text-white'
                          : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.8">{!! $item['icon'] !!}</svg>
                {{ $item['label'] }}
            </a>
        @endforeach
        @endrole

        @role('CLIENTE')
        @php
            $linksCliente = [
                [
                    'label'  => 'Check-in',
                    'href'   => route('checkin'),
                    'active' => request()->routeIs('checkin'),
                    'icon'   => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
                ],
                [
                    'label'  => 'Quórum',
                    'href'   => url('/quorum'),
                    'active' => request()->is('quorum'),
                    'icon'   => '<line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/>',
                ],
            ];
        @endphp
        @foreach($linksCliente as $item)
            <a href="{{ $item['href'] }}"
               x-on:click="sidebarOpen = false"
               class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-semibold transition-colors
                      {{ $item['active']
                          ? 'bg-white/15 text-white'
                          : 'text-white/70 hover:bg-white/10 hover:text-white' }}">
                <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24"
                     stroke="currentColor" stroke-width="1.8">{!! $item['icon'] !!}</svg>
                {{ $item['label'] }}
            </a>
        @endforeach
        @endrole

    </nav>

    {{-- User section --}}
    <div class="border-t border-white/10 px-4 py-4 shrink-0">
        <div class="flex items-center gap-2 mb-1 min-w-0">
            <span class="text-white text-sm font-semibold truncate">{{ Auth::user()->name }}</span>
            <span class="shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold
                         bg-white/15 text-white/80 border border-white/20">
                {{ Auth::user()->getRoleNames()->first() ?? '—' }}
            </span>
        </div>
        <div class="text-white/50 text-xs truncate mb-3">{{ Auth::user()->email }}</div>
        <div class="flex items-center gap-3 text-xs">
            <a href="{{ route('profile.edit') }}"
               x-on:click="sidebarOpen = false"
               class="text-white/60 hover:text-white transition">
                Perfil
            </a>
            <span class="text-white/20">·</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="text-white/60 hover:text-white transition">
                    Salir →
                </button>
            </form>
        </div>
    </div>
</aside>
