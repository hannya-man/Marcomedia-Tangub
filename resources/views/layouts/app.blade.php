@php
    // Just logged in (AuthenticatedSessionController): start under the brand screen. Read once.
    $authArriving = session()->pull('auth_splash') === 'in';
    // Approvals: for an owner, cashiers' requests waiting; for a cashier, answers not seen yet.
    $isOwnerNav = auth()->user()->role === 'admin';
    $approvalCount = $isOwnerNav
        ? \App\Models\ApprovalRequest::pending()->count()
        : \App\Models\ApprovalRequest::where('requested_by', auth()->id())->where('status', '!=', 'pending')->whereNull('requester_seen_at')->count();
@endphp
<!DOCTYPE html>
<html lang="en">
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

    {{-- Compiled Tailwind (via Vite) — replaces the old cdn.tailwindcss.com
         script, which recompiled styles in the browser on every single page
         load and caused a visible "unstyled/broken" flash on navigation. --}}
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    {{-- A fixed version: "latest" was fetched through a redirect on every page, and a new release
         can rename icons. Chart.js loads on the dashboard only (its @push). --}}
    <script src="https://unpkg.com/lucide@1.55.0/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>
        // Just logged in: this page starts under the brand screen the login page faded in
        // (partials/auth-splash), and fades it away. The usual page transition is skipped then,
        // both ways: the two pages meet on that same screen.
        const arrivingUnderSplash = @json($authArriving);
        window.addEventListener('pagereveal', (e) => {
            if (arrivingUnderSplash && e.viewTransition) e.viewTransition.skipTransition();
        });
        window.addEventListener('pageswap', (e) => {
            if (e.viewTransition && document.getElementById('auth-splash')?.classList.contains('is-on')) e.viewTransition.skipTransition();
        });

        // Lightweight "page is loading" bar for link clicks and form submits —
        // gives full page navigations (this app doesn't use an SPA router)
        // a smoother, more app-like feel instead of a blank flash.
        window.loadingBar = {
            el: null,
            start() {
                if (!this.el) return;
                this.el.style.transition = '';
                this.el.style.opacity = '1';
                this.el.style.width = '70%';
            },
            done() {
                if (!this.el) return;
                this.el.style.width = '100%';
                setTimeout(() => {
                    this.el.style.opacity = '0';
                    setTimeout(() => { this.el.style.transition = 'none'; this.el.style.width = '0%'; }, 300);
                }, 200);
            },
        };
        document.addEventListener('DOMContentLoaded', () => {
            const bar = document.createElement('div');
            bar.id = 'page-loading-bar';
            document.body.prepend(bar);
            loadingBar.el = bar;
        });

        // A list that pages and filters in place (data-smooth-list on its container, around its
        // items and page links): the next page is fetched and swapped in with a short fade, instead
        // of reloading the whole screen. The address and Back/Forward keep working. Anything
        // unexpected (logged out meanwhile, an error) falls back to an ordinary page load.
        window.smoothList = {
            token: 0,
            async load(url, { history: mode = 'push', scroll = false } = {}) {
                const list = document.querySelector('[data-smooth-list]');
                if (!list) { location.href = url; return; }
                const token = ++this.token;   // only the latest request lands
                loadingBar.start();
                list.classList.add('is-loading');
                let fresh;
                try {
                    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' } });
                    if (!res.ok || res.redirected) throw new Error('fall back');
                    fresh = new DOMParser().parseFromString(await res.text(), 'text/html').getElementById(list.id);
                    if (!fresh) throw new Error('fall back');
                } catch (err) {
                    if (token === this.token) location.href = url;
                    return;
                }
                if (token !== this.token) return;

                fresh.classList.add('is-entering');
                list.replaceWith(fresh);
                if (window.lucide) lucide.createIcons({ root: fresh });
                if (mode === 'push') history.pushState({ smoothList: true }, '', url);
                else if (mode === 'replace') history.replaceState({ smoothList: true }, '', url);
                requestAnimationFrame(() => requestAnimationFrame(() => fresh.classList.remove('is-entering')));
                if (scroll && fresh.getBoundingClientRect().top < 0) fresh.scrollIntoView({ behavior: 'smooth', block: 'start' });
                loadingBar.done();
                document.dispatchEvent(new CustomEvent('smooth-list:loaded', { detail: { url } }));
            },
        };
        // Its page links. Registered before the loading bar's own listener below, which then
        // leaves these clicks alone.
        document.addEventListener('click', (e) => {
            const link = e.target.closest('[data-smooth-list] nav[aria-label="Pagination"] a[href]');
            if (!link || e.button !== 0 || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey) return;
            e.preventDefault();
            smoothList.load(link.href, { scroll: true });
        });
        window.addEventListener('popstate', () => {
            if (document.querySelector('[data-smooth-list]')) smoothList.load(location.href, { history: 'none' });
        });

        document.addEventListener('click', (e) => {
            if (e.defaultPrevented || e.ctrlKey || e.metaKey || e.shiftKey) return;
            const link = e.target.closest('a[href]');
            if (link && link.origin === window.location.origin && !link.hasAttribute('download') && link.target !== '_blank') {
                loadingBar.start();
            }
        });
        document.addEventListener('submit', (e) => { if (!e.defaultPrevented) loadingBar.start(); });
    </script>
    @stack('scripts-head')
</head>
<body class="bg-slate-50 text-slate-800" x-data="{ mobileNavOpen: false, confirmingLogout: false }">
{{-- First in the page, so it covers it from the very first paint when arriving from the login page --}}
@include('partials.auth-splash', ['arriving' => $authArriving])
<div class="flex">

    {{-- Mobile backdrop — tap outside the drawer to close it --}}
    <div x-show="mobileNavOpen" x-transition.opacity @click="mobileNavOpen = false"
         class="fixed inset-0 bg-black/50 z-30 lg:hidden" style="display:none;"></div>

    {{-- ===================== SIDEBAR ===================== --}}
    {{-- Desktop: sticky and one screen tall, so only the page scrolls. Phone: slide-out drawer.
         h-dvh is the visible screen height: 100vh is taller on phones (it ignores the browser's
         toolbar), which pushed the user block off the bottom of the screen. --}}
    <aside :class="mobileNavOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
           class="w-64 bg-ink flex-shrink-0 flex flex-col h-dvh fixed lg:sticky lg:top-0 inset-y-0 left-0 z-40 transition-transform duration-300 ease-in-out">
        {{-- The logo without its dark circle, big enough to read: the circle was the sidebar's own
             dark, so it showed as a small dark blob. --}}
        <div class="relative px-5 pt-4 pb-1">
            <a href="{{ url('/') }}" class="block w-fit mx-auto">
                <img src="{{ asset('images/marcomedia-logo-transparent.png') }}" alt="Marcomedia: the best place to get customized" class="h-24 w-auto">
            </a>
            <button @click="mobileNavOpen = false" aria-label="Close menu" class="lg:hidden absolute top-3 right-3 text-slate-400 hover:text-white">
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
            @if(auth()->user()->role === 'cashier')
            {{-- What they asked an owner to approve; the number is answers they haven't seen --}}
            <a href="{{ route('approvals.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('approvals.*') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <i data-lucide="inbox" class="w-4 h-4"></i><span class="flex-1">My Requests</span>
                @if($approvalCount)<span class="min-w-[1.25rem] h-5 px-1.5 rounded-full bg-amber-400 text-ink text-[11px] font-semibold flex items-center justify-center" title="New answers">{{ $approvalCount }}</span>@endif
            </a>
            @endif

            @if(in_array(auth()->user()->role, ['admin', 'cashier']))
            <p class="px-3 pb-1 pt-4 text-[10px] uppercase tracking-widest text-slate-500 font-semibold">Inventory</p>
            {{-- The four inventory types. The materials archive belongs to whichever page opened it. --}}
            @php $archiveFrom = request()->routeIs('materials.archive') ? (request('from') === 'discrete' ? 'discrete' : 'continuous') : null; @endphp
            <a href="{{ route('inventory.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('inventory.index', 'inventory.archive', 'inventory.categories', 'categories.archive') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
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
            {{-- Cashiers' requests (a new or changed product, a void); the number is how many wait --}}
            <a href="{{ route('approvals.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm {{ request()->routeIs('approvals.*') ? 'bg-brand-600 text-white' : 'text-slate-300 hover:bg-white/5 hover:text-white' }}">
                <i data-lucide="inbox" class="w-4 h-4"></i><span class="flex-1">Approvals</span>
                @if($approvalCount)<span class="min-w-[1.25rem] h-5 px-1.5 rounded-full bg-red-500 text-white text-[11px] font-semibold flex items-center justify-center" title="Waiting for you">{{ $approvalCount }}</span>@endif
            </a>
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
                 class="absolute bottom-full left-3 right-3 mb-2 overflow-hidden rounded-lg border border-white/10 bg-zinc-800 p-1 shadow-lg z-50">
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
        <header class="h-16 bg-white border-b border-slate-200 flex items-center justify-between px-4 sm:px-8">
            <div class="flex items-center gap-3">
                <button @click="mobileNavOpen = true" class="lg:hidden w-9 h-9 rounded-lg hover:bg-slate-100 flex items-center justify-center text-slate-600">
                    <i data-lucide="menu" class="w-5 h-5"></i>
                </button>
                <h1 class="text-base sm:text-lg font-semibold text-ink truncate">@yield('title', 'Dashboard')</h1>
            </div>
            <div class="flex items-center gap-4">
                {{-- Approvals waiting (owner) or new answers (cashier): also reachable on a phone,
                     where the sidebar is hidden --}}
                @if($approvalCount && ! request()->routeIs('approvals.*'))
                    <a href="{{ route('approvals.index') }}" title="{{ $isOwnerNav ? 'Waiting for your approval' : 'New answers to your requests' }}"
                       class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-full px-3 py-1.5 text-xs font-medium {{ $isOwnerNav ? 'bg-red-50 text-red-700 hover:bg-red-100' : 'bg-amber-50 text-amber-800 hover:bg-amber-100' }}">
                        <i data-lucide="inbox" class="w-3.5 h-3.5"></i>
                        {{ $approvalCount }}<span class="hidden sm:inline">{{ $isOwnerNav ? ' to approve' : ($approvalCount === 1 ? ' new answer' : ' new answers') }}</span>
                    </a>
                @endif
                {{-- Profile / Logout. The sidebar's user menu has the same two actions. --}}
                <div class="relative" x-data="{ menuOpen: false }">
                    <button @click="menuOpen = !menuOpen" @click.outside="menuOpen = false"
                        class="w-9 h-9 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xs font-semibold">
                        {{ collect(explode(' ', auth()->user()->name ?? 'A N'))->map(fn($p) => strtoupper($p[0] ?? ''))->join('') }}
                    </button>

                    {{-- shadcn DropdownMenu style: rounded-md panel, p-1 padding, fade+zoom entrance --}}
                    <div x-show="menuOpen"
                         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
                         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
                         style="display: none;"
                         class="absolute right-0 mt-2 w-48 overflow-hidden rounded-md border border-slate-200 bg-white p-1 shadow-md z-50">
                        <a href="{{ route('profile.edit') }}" class="flex items-center gap-2 rounded-sm px-2 py-1.5 text-sm text-slate-700 hover:bg-slate-100 transition-colors">
                            <i data-lucide="user" class="w-4 h-4"></i> Profile
                        </a>
                        <div class="-mx-1 my-1 h-px bg-slate-200"></div>
                        <button type="button" @click="menuOpen = false; confirmingLogout = true"
                            class="w-full flex items-center gap-2 rounded-sm px-2 py-1.5 text-sm text-red-600 hover:bg-red-50 transition-colors">
                            <i data-lucide="log-out" class="w-4 h-4"></i> Logout
                        </button>
                    </div>
                </div>
            </div>
        </header>

        @include('stock._alert_banner')

        @if (session('success'))
            <div class="mx-8 mt-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-700 px-4 py-3 text-sm">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="mx-8 mt-4 rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm">
                {{ session('error') }}
            </div>
        @endif
        {{-- Why a form wasn't saved, on pages that don't say it themselves (those declare
             @section('shows-errors')). It used to be dropped: the form just came back. --}}
        @if ($errors->any())
            @sectionMissing('shows-errors')
                <div class="mx-8 mt-4 rounded-lg bg-red-50 border border-red-200 text-red-700 px-4 py-3 text-sm">
                    @foreach (collect($errors->all())->unique() as $message)
                        <p>{{ $message }}</p>
                    @endforeach
                </div>
            @endif
        @endif

        {{-- overflow clip, not hidden: hidden made <main> a scroll box of its own, and a bar inside it (sticky) couldn't stay on screen --}}
        <main class="flex-1 p-4 sm:p-6 lg:p-8 w-full overflow-x-clip">
            @yield('content')
        </main>
    </div>
</div>

{{-- shadcn-style AlertDialog — confirm before logging out. Opened from the header menu or the sidebar's user menu. --}}
<div x-show="confirmingLogout" style="display:none;" x-transition.opacity
     class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="confirmingLogout = false" @keydown.escape.window="confirmingLogout = false">
    <div x-show="confirmingLogout" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         class="bg-white rounded-lg shadow-lg border border-slate-200 w-full max-w-sm p-6">
        <p class="text-lg font-semibold text-ink mb-2">Log out of Marcomedia POS?</p>
        <p class="text-sm text-slate-500 mb-6">You'll need to sign in again to access the dashboard.</p>
        <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
            <button @click="confirmingLogout = false"
                class="border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-md px-4 py-2">
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

<script>
// Logging out: the brand screen fades in, then the form is sent; the login page starts under the
// same screen and fades it away.
function playLogoutTransition(form) {
    authSplash.show('Logging out…');
    setTimeout(() => form.submit(), 300);
    return false;
}
</script>

{{-- Shown when the login has ended while this page sat open (a sleeping PC overnight) --}}
<div id="session-ended" style="display:none;" role="alert"
     class="fixed top-3 left-1/2 -translate-x-1/2 z-[60] w-[calc(100%-2rem)] max-w-md rounded-lg border border-amber-300 bg-amber-50 shadow-lg px-4 py-3 text-sm text-amber-800 flex items-center gap-3">
    <span class="flex-1">You've been logged out while this page was open. Log in again before ringing anything up.</span>
    <a href="{{ route('login') }}" class="flex-shrink-0 rounded-md bg-ink text-white text-xs font-medium px-3 py-1.5">Log in</a>
</div>
<script>
// Keeps the login alive while a page sits open: Billing/POS stays open on the counter all day, and
// a sale sent after two quiet hours failed with "Page Expired", losing its cart. Coming back to a
// page whose login has already ended (a PC asleep overnight) says so before anything is typed.
(function () {
    const ping = () => fetch(@json(route('keep-alive')), { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
        .then((res) => { if (res.status === 401 || res.status === 419) document.getElementById('session-ended').style.display = 'flex'; })
        .catch(() => {});   // offline for a moment: the next one tries again
    setInterval(ping, 10 * 60 * 1000);
    document.addEventListener('visibilitychange', () => { if (document.visibilityState === 'visible') ping(); });
})();
</script>

<script>lucide.createIcons();</script>
@stack('scripts')
</body>
</html>
