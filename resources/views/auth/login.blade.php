@php
    // Just logged out, or a login refused: this page starts under the brand screen the last page
    // faded in (partials/auth-splash), and fades it away to show the card.
    $authArriving = session()->pull('auth_splash') === 'out' || $errors->any();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Login - Marcomedia POS</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0f172a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
    <link rel="icon" href="/icons/icon-192.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@1.55.0/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>
        // Pages meet on the brand screen when logging in or out, so the page transition is skipped then.
        const arrivingUnderSplash = @json($authArriving);
        window.addEventListener('pagereveal', (e) => {
            if (arrivingUnderSplash && e.viewTransition) e.viewTransition.skipTransition();
        });
        window.addEventListener('pageswap', (e) => {
            if (e.viewTransition && document.getElementById('auth-splash')?.classList.contains('is-on')) e.viewTransition.skipTransition();
        });
    </script>
</head>
<body class="auth-backdrop min-h-screen flex items-center justify-center p-4">
    {{-- First in the page, so it covers it from the very first paint after logging out --}}
    @include('partials.auth-splash', ['arriving' => $authArriving])
@php
    // One look for both boxes: room inside, an icon on the left, and a single soft ring on focus
    // (no second outline around it). Red when the login was refused.
    $field = 'block w-full h-11 rounded-lg border bg-white pl-10 text-sm text-ink placeholder:text-slate-400 shadow-sm focus:outline-none focus:ring-2 transition';
    $ok = 'border-slate-300 focus:border-brand-500 focus:ring-brand-500/20';
    $bad = 'border-red-400 focus:border-red-500 focus:ring-red-500/20';
@endphp

    <div class="login-card w-full max-w-sm">
        {{-- The logo sits on the dark background, where its teal, gray and yellow all show --}}
        <img src="{{ asset('images/marcomedia-logo-transparent.png') }}" alt="Marcomedia: the best place to get customized" class="h-32 sm:h-40 w-auto mx-auto mb-6">

        <div class="bg-white rounded-2xl shadow-xl p-8" x-data="{ showPassword: false }">
            <div class="text-center mb-7">
                <p class="text-lg font-semibold text-ink">Marcomedia POS</p>
                <p class="text-sm text-slate-500 mt-1">The best place to get customized!</p>
            </div>

            @if (session('status'))
                <div class="mb-4 text-sm text-emerald-600 text-center">{{ session('status') }}</div>
            @endif

            <form id="login-form" method="POST" action="{{ route('login') }}" class="space-y-4">
                @csrf

                <div>
                    <label for="email" class="block mb-1.5 text-xs font-medium text-slate-600">Email</label>
                    <div class="relative">
                        <i data-lucide="mail" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                        <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                               placeholder="you@example.com" class="{{ $field }} pr-3.5 {{ $errors->has('email') ? $bad : $ok }}">
                    </div>
                    @error('email')
                        <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="password" class="block mb-1.5 text-xs font-medium text-slate-600">Password</label>
                    <div class="relative">
                        <i data-lucide="lock" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                        <input id="password" :type="showPassword ? 'text' : 'password'" type="password" name="password" required autocomplete="current-password"
                               placeholder="Your password" class="{{ $field }} pr-11 {{ $errors->has('password') ? $bad : $ok }}">
                        <button type="button" @click="showPassword = !showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'"
                                class="absolute inset-y-0 right-0 w-11 flex items-center justify-center text-slate-400 hover:text-slate-600">
                            <i data-lucide="eye" class="w-4 h-4" x-show="!showPassword"></i>
                            <i data-lucide="eye-off" class="w-4 h-4" x-show="showPassword" style="display:none;"></i>
                        </button>
                    </div>
                    @error('password')
                        <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-sm text-slate-600 select-none cursor-pointer">
                        <input type="checkbox" name="remember" class="w-4 h-4 rounded border-slate-300 accent-brand-600"> Remember me
                    </label>
                    {{-- Only once email is set up on the server (App\Support\EmailRecovery) --}}
                    @if (\App\Support\EmailRecovery::available())
                        <a href="{{ route('password.request') }}" class="text-sm text-brand-600 hover:underline">Forgot your password?</a>
                    @endif
                </div>

                <button type="submit" class="w-full h-11 bg-ink hover:bg-slate-800 text-white text-sm font-medium rounded-lg">
                    Log In
                </button>
            </form>
        </div>
    </div>

    <script>
    lucide.createIcons();

    // Signing in: the brand screen fades in, then the form is sent. The first page of the app
    // starts under the same screen and fades it away. (Sent at once if this script never runs.)
    document.getElementById('login-form').addEventListener('submit', function (e) {
        if (this.dataset.sending) return;
        e.preventDefault();
        this.dataset.sending = '1';
        const button = this.querySelector('button[type=submit]');
        button.disabled = true;
        button.textContent = 'Signing in…';
        authSplash.show('Signing in…');
        setTimeout(() => this.submit(), 300);
    });
    // Back to this page from the browser's page cache: ready to sign in again.
    window.addEventListener('pageshow', (e) => {
        if (!e.persisted) return;
        const form = document.getElementById('login-form');
        delete form.dataset.sending;
        const button = form.querySelector('button[type=submit]');
        button.disabled = false;
        button.textContent = 'Log In';
    });
    </script>
</body>
</html>
