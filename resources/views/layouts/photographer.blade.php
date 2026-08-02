<!DOCTYPE html>
<html lang="en" x-data="{ dark: localStorage.getItem('darkMode') === 'true' }" x-init="$watch('dark', v => localStorage.setItem('darkMode', v))" :class="{ 'dark': dark }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'My Appointments') - Marcomedia POS</title>
    <script>
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            darkMode: 'class',
            theme: { extend: {
                colors: { ink:'#0f172a', brand:{50:'#eef2ff',100:'#e0e7ff',500:'#6366f1',600:'#4f46e5',700:'#4338ca'} },
                fontFamily: { sans: ['Inter','ui-sans-serif','system-ui'] },
            } }
        }
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <style> body { font-family: 'Inter', ui-sans-serif, system-ui, sans-serif; } </style>
</head>
<body class="bg-slate-50 dark:bg-slate-900 text-slate-800 dark:text-slate-100 transition-colors">
<div class="flex">
    <aside class="w-64 bg-ink flex-shrink-0 hidden lg:flex lg:flex-col min-h-screen">
        <div class="flex items-center gap-3 px-5 h-16 border-b border-white/10">
            <div class="w-9 h-9 rounded-lg bg-brand-600 flex items-center justify-center text-white font-bold text-sm">M</div>
            <div>
                <p class="text-white text-sm font-semibold leading-tight">Marcomedia POS</p>
                <p class="text-[10px] text-slate-400 uppercase tracking-wider">Photographer</p>
            </div>
        </div>
        <nav class="flex-1 px-3 py-4 space-y-1">
            <p class="px-3 pb-1 pt-2 text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Main</p>
            <a href="{{ route('photographer.appointments.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('photographer.appointments.*') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <i data-lucide="calendar-days" class="w-4 h-4"></i><span>My Appointments</span>
            </a>
        </nav>
        <div class="border-t border-white/10 p-4" x-data="{ menuOpen: false }">
            <div class="relative">
                <button @click="menuOpen = !menuOpen" @click.outside="menuOpen = false" class="w-full flex items-center gap-3 mb-1 text-left">
                    <div class="w-9 h-9 rounded-full bg-slate-700 flex items-center justify-center text-xs text-white font-medium">
                        {{ collect(explode(' ', auth()->user()->name ?? 'K B'))->map(fn($p) => strtoupper($p[0] ?? ''))->join('') }}
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="text-sm text-white font-medium truncate">{{ auth()->user()->name ?? 'Photographer' }}</p>
                        <p class="text-[10px] text-slate-400 uppercase tracking-wider">Photographer</p>
                    </div>
                    <i data-lucide="chevron-up" class="w-3.5 h-3.5 text-slate-400"></i>
                </button>
                <div x-show="menuOpen" x-transition style="display: none;"
                     class="absolute bottom-full left-0 right-0 mb-2 bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 overflow-hidden">
                    <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 px-4 py-2.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-50 dark:hover:bg-slate-700">
                        <i data-lucide="user" class="w-4 h-4"></i> Profile
                    </a>
                    <form method="POST" action="{{ route('logout') }}" class="border-t border-slate-100 dark:border-slate-700">
                        @csrf
                        <button type="submit" class="w-full flex items-center gap-2 px-4 py-2.5 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20">
                            <i data-lucide="log-out" class="w-4 h-4"></i> Logout
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </aside>

    <div class="flex-1 min-w-0 flex flex-col">
        <header class="h-16 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-8">
            <h1 class="text-lg font-semibold text-ink dark:text-white">@yield('title', 'My Appointments')</h1>
            <div class="flex items-center gap-4">
                <button @click="dark = !dark" class="w-9 h-9 rounded-full hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center justify-center text-slate-500 dark:text-slate-300" title="Toggle dark mode">
                    <i data-lucide="moon" class="w-4 h-4" x-show="!dark"></i>
                    <i data-lucide="sun" class="w-4 h-4" x-show="dark" style="display:none;"></i>
                </button>
                <a href="{{ route('profile.edit') }}" class="w-9 h-9 rounded-full bg-brand-100 dark:bg-brand-600 text-brand-700 dark:text-white flex items-center justify-center text-xs font-semibold">
                    {{ collect(explode(' ', auth()->user()->name ?? 'K B'))->map(fn($p) => strtoupper($p[0] ?? ''))->join('') }}
                </a>
            </div>
        </header>
        <main class="flex-1 p-8">
            @yield('content')
        </main>
    </div>
</div>
<script>lucide.createIcons();</script>
</body>
</html>
