<!DOCTYPE html>
<html lang="en" x-data="{ dark: localStorage.getItem('darkMode') === 'true' }" x-init="$watch('dark', v => localStorage.setItem('darkMode', v))" :class="{ 'dark': dark }">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>@yield('title', 'Dashboard') - Marcomedia POS</title>

    {{-- PWA: lets this be "installed" to the home screen on Android/iOS --}}
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0f172a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="Marcomedia">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="icon" href="/icons/icon-192.png">
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
        }
    </script>

    {{-- Prevents a flash of light mode before Alpine initializes --}}
    <script>
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>

    {{-- Prevents a flash of light mode before Alpine initializes --}}
    <script>
        if (localStorage.getItem('darkMode') === 'true') {
            document.documentElement.classList.add('dark');
        }
    </script>

    {{-- Compiled Tailwind (via Vite) — replaces the old cdn.tailwindcss.com
         script, which recompiled styles in the browser on every single page
         load and caused a visible "unstyled/broken" flash on navigation. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>
        // Lightweight "page is loading" bar for link clicks and form submits —
        // gives full page navigations (this app doesn't use an SPA router)
        // a smoother, more app-like feel instead of a blank flash.
        document.addEventListener('DOMContentLoaded', () => {
            const bar = document.createElement('div');
            bar.id = 'page-loading-bar';
            document.body.prepend(bar);

            const startLoading = () => {
                bar.style.opacity = '1';
                bar.style.width = '70%';
            };

            document.addEventListener('click', (e) => {
                const link = e.target.closest('a[href]');
                if (link && link.origin === window.location.origin && !link.hasAttribute('download') && link.target !== '_blank') {
                    startLoading();
                }
            });
            document.addEventListener('submit', () => startLoading());
        });
    </script>
    @stack('scripts-head')
</head>
<body class="bg-slate-50 dark:bg-slate-900 text-slate-800 dark:text-slate-100 transition-colors" x-data="{ mobileNavOpen: false, confirmingLogout: false }">
<div class="flex">

    {{-- Mobile backdrop — tap outside the drawer to close it --}}
    <div x-show="mobileNavOpen" x-transition.opacity @click="mobileNavOpen = false"
         class="fixed inset-0 bg-black/50 z-30 lg:hidden" style="display:none;"></div>

    {{-- ===================== SIDEBAR ===================== --}}
    {{-- Desktop: sticky and one screen tall, so only the page scrolls. Phone: slide-out drawer.
         h-dvh is the visible screen height: 100vh is taller on phones (it ignores the browser's
         toolbar), which pushed the user block off the bottom of the screen. --}}
    <aside :class="mobileNavOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="w-64 bg-ink dark:bg-slate-950 dark:border-r dark:border-slate-800 flex-shrink-0 flex flex-col h-dvh fixed lg:sticky lg:top-0 inset-y-0 left-0 z-40 transition-transform duration-300 ease-in-out">
        <div class="flex items-center justify-between gap-3 px-5 h-16 border-b border-white/10">
            <div class="flex items-center gap-3">
                <img src="{{ asset('images/marcomedia-logo.png') }}" alt="Marcomedia" class="w-9 h-9 rounded-full object-cover flex-shrink-0">
                <div>
                    <p class="text-white text-sm font-semibold leading-tight">Marcomedia POS</p>
                    <p class="text-[10px] text-slate-400 uppercase tracking-wider">Management</p>
                </div>
            </div>
            <button @click="mobileNavOpen = false" class="lg:hidden text-slate-400 hover:text-white">
                <i data-lucide="x" class="w-5 h-5"></i>
            </button>
        </div>

        <nav class="flex-1 px-3 py-4 space-y-1 overflow-y-auto">
            @if(auth()->user()->role === 'admin')
            <p class="px-3 pb-1 pt-2 text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Main</p>
            <a href="{{ route('dashboard') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('dashboard') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <i data-lucide="layout-dashboard" class="w-4 h-4"></i><span>Dashboard</span>
            </a>
            @endif

            @if(in_array(auth()->user()->role, ['admin', 'cashier']))
            <p class="px-3 pb-1 pt-4 text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Operations</p>
            <a href="{{ route('pos.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('pos.*') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <i data-lucide="scan-line" class="w-4 h-4"></i><span>Billing / POS</span>
            </a>
            @endif
            @if(in_array(auth()->user()->role, ['admin', 'cashier']))
            <a href="{{ route('sales.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('sales.*') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <i data-lucide="banknote" class="w-4 h-4"></i><span>Sales</span>
            </a>
            @endif

            @if(in_array(auth()->user()->role, ['admin', 'cashier']))
            <p class="px-3 pb-1 pt-4 text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Inventory</p>
            {{-- The four inventory types. The materials archive belongs to whichever page opened it. --}}
            @php $archiveFrom = request()->routeIs('materials.archive') ? (request('from') === 'discrete' ? 'discrete' : 'continuous') : null; @endphp
            <a href="{{ route('inventory.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('inventory.index') || request()->routeIs('inventory.archive') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <i data-lucide="package" class="w-4 h-4"></i><span>Products</span>
            </a>
            <a href="{{ route('stock.continuous') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('stock.continuous') || $archiveFrom === 'continuous' ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <i data-lucide="ruler" class="w-4 h-4"></i><span>Continuous Raw Materials</span>
            </a>
            <a href="{{ route('stock.discrete') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('stock.discrete') || $archiveFrom === 'discrete' ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <i data-lucide="boxes" class="w-4 h-4"></i><span>Discrete Materials</span>
            </a>
            <a href="{{ route('stock.rejected') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('stock.rejected') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <i data-lucide="recycle" class="w-4 h-4"></i><span>Scrapped / Rejected</span>
            </a>
            @endif

            @if(auth()->user()->role === 'admin')
            <p class="px-3 pb-1 pt-4 text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Admin</p>
            <a href="{{ route('users.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('users.*') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <i data-lucide="users" class="w-4 h-4"></i><span>User Management</span>
            </a>
            @endif
        </nav>

        {{-- ===================== USER MENU — Profile / Log out, same as the header avatar ===================== --}}
        <div class="relative border-t border-white/10 p-3" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
            {{-- Opens upward, above the name --}}
            <div x-show="open"
                 x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-1"
                 style="display: none;" role="menu"
                 class="absolute bottom-full left-3 right-3 mb-2 overflow-hidden rounded-lg border border-white/10 bg-zinc-800 dark:bg-slate-800 p-1 shadow-lg z-50">
                <p class="px-2 pt-1.5 pb-2 mb-1 text-xs text-slate-400 truncate border-b border-white/10">{{ auth()->user()->email }}</p>
                <a href="{{ route('profile.edit') }}" role="menuitem"
                   class="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-slate-200 hover:bg-white/10 hover:text-white">
                    <i data-lucide="user" class="w-4 h-4"></i> Profile
                </a>
                <button type="button" role="menuitem" @click="open = false; mobileNavOpen = false; confirmingLogout = true"
                    class="w-full flex items-center gap-2 rounded-md px-2 py-1.5 text-sm text-red-400 hover:bg-red-500/10">
                    <i data-lucide="log-out" class="w-4 h-4"></i> Log out
                </button>
            </div>

            <button type="button" @click="open = !open" aria-haspopup="menu" :aria-expanded="open"
                class="w-full flex items-center gap-3 rounded-lg p-2 text-left hover:bg-white/5" :class="open ? 'bg-white/5' : ''">
                <div class="w-9 h-9 rounded-full bg-slate-700 flex items-center justify-center text-xs text-white font-medium flex-shrink-0">
                    {{ collect(explode(' ', auth()->user()->name ?? 'A N'))->map(fn($p) => strtoupper($p[0] ?? ''))->join('') }}
                </div>
                <div class="min-w-0 flex-1">
                    <p class="text-sm text-white font-medium truncate">{{ auth()->user()->name ?? 'Guest' }}</p>
                    <p class="text-[10px] text-slate-400 uppercase tracking-wider">{{ auth()->user()->role ?? 'User' }}</p>
                </div>
                <i data-lucide="chevrons-up-down" class="w-4 h-4 text-slate-400 flex-shrink-0"></i>
            </button>
        </div>
    </aside>

    {{-- ===================== MAIN COLUMN ===================== --}}
    <div class="flex-1 min-w-0 flex flex-col w-full">
        <header class="h-16 bg-white dark:bg-slate-800 border-b border-slate-200 dark:border-slate-700 flex items-center justify-between px-4 sm:px-8">
            <div class="flex items-center gap-3">
                <button @click="mobileNavOpen = true" class="lg:hidden w-9 h-9 rounded-lg hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center justify-center text-slate-600 dark:text-slate-300">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <h1 class="text-base sm:text-lg font-semibold text-ink dark:text-white truncate">@yield('title', 'Dashboard')</h1>
            </div>
            <div class="flex items-center gap-4">
                {{-- Functional dark mode toggle --}}
                <button @click="dark = !dark" class="w-9 h-9 rounded-full hover:bg-slate-100 dark:hover:bg-slate-700 flex items-center justify-center text-slate-500 dark:text-slate-300" title="Toggle dark mode">
                    <i data-lucide="moon" class="w-4 h-4" x-show="!dark"></i>
                    <i data-lucide="sun" class="w-4 h-4" x-show="dark" style="display:none;"></i>
                </button>

                {{-- Profile / Logout. The sidebar's user menu has the same two actions. --}}
                <div class="relative" x-data="{ menuOpen: false }">
                    <button @click="menuOpen = !menuOpen" @click.outside="menuOpen = false"
                        class="w-9 h-9 rounded-full bg-brand-100 dark:bg-brand-600 text-brand-700 dark:text-white flex items-center justify-center text-xs font-semibold">
                        {{ collect(explode(' ', auth()->user()->name ?? 'A N'))->map(fn($p) => strtoupper($p[0] ?? ''))->join('') }}
                    </button>

                    {{-- shadcn DropdownMenu style: rounded-md panel, p-1 padding, fade+zoom entrance --}}
                    <div x-show="menuOpen"
                         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                         style="display: none;"
                         class="absolute right-0 mt-2 w-48 overflow-hidden rounded-md border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-1 shadow-md z-50">
                        <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 rounded-sm px-2 py-1.5 text-sm text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors">
                            <i data-lucide="user" class="w-4 h-4"></i> Profile
                        </a>
                        <div class="-mx-1 my-1 h-px bg-slate-200 dark:bg-slate-700"></div>
                        <button type="button" @click="menuOpen = false; confirmingLogout = true"
                            class="w-full flex items-center gap-2 rounded-sm px-2 py-1.5 text-sm text-red-600 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                            <i data-lucide="log-out" class="w-4 h-4"></i> Logout
                        </button>
                    </div>
                </div>
            </div>
        </header>

        @include('stock._alert_banner')

        @if (session('success'))
            <div class="mx-8 mt-4 rounded-lg bg-emerald-50 dark:bg-emerald-900/30 border border-emerald-200 dark:border-emerald-800 text-emerald-700 dark:text-emerald-300 px-4 py-3 text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mx-8 mt-4 rounded-lg bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-300 px-4 py-3 text-sm">
                {{ session('error') }}
            </div>
        @endif

        <main class="flex-1 p-4 sm:p-6 lg:p-8 w-full overflow-x-hidden">
            @yield('content')
        </main>
    </div>
</div>

{{-- shadcn-style AlertDialog — confirm before logging out. Opened from the header menu or the sidebar's user menu. --}}
<div x-show="confirmingLogout" style="display:none;" x-transition.opacity
     class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="confirmingLogout = false" @keydown.escape.window="confirmingLogout = false">
    <div x-show="confirmingLogout" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         class="bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 w-full max-w-sm p-6">
        <p class="text-lg font-semibold text-ink dark:text-white mb-2">Log out of Marcomedia POS?</p>
        <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">You'll need to sign in again to access the dashboard.</p>
        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
            <button @click="confirmingLogout = false"
                class="border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium rounded-md px-4 py-2">
                Cancel
            </button>
            <button @click="playLogoutTransition(document.getElementById('logout-form'))"
                class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">
                Log out
            </button>
        </div>
    </div>
</div>

<form id="logout-form" method="POST" action="{{ route('logout') }}" class="hidden">
    @csrf
</form>

<div id="logout-overlay" class="fixed inset-0 bg-ink z-[9999] flex items-center justify-center opacity-0 pointer-events-none transition-opacity duration-300">
    <div class="text-white text-sm flex items-center gap-3">
        <svg class="animate-spin h-5 w-5" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
        Logging out…
    </div>
</div>
<script>
function playLogoutTransition(form) {
    const overlay = document.getElementById('logout-overlay');
    overlay.classList.remove('opacity-0', 'pointer-events-none');
    overlay.classList.add('opacity-100');
    setTimeout(() => form.submit(), 350);
    return false;
}
</script>

<script>lucide.createIcons();</script>
@stack('scripts')
</body>
</html>
