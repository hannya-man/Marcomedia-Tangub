@php
    // Arrived from the login page, which faded in the brand screen (partials/auth-splash), or a code
    // refused: this page starts under it and fades it away to show the card, like the login page.
    $authArriving = session()->pull('auth_splash') === 'out' || $errors->any();
    $useRecovery = (bool) old('use_recovery');
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Enter your code - Marcomedia POS</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0f172a">
    <link rel="icon" href="/icons/icon-192.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@1.55.0/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>
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
    @include('partials.auth-splash', ['arriving' => $authArriving])
@php
    $field = 'block w-full h-12 rounded-lg border bg-white text-center text-ink shadow-sm focus:outline-none focus:ring-2 transition';
    $ok = 'border-slate-300 focus:border-brand-500 focus:ring-brand-500/20';
    $bad = 'border-red-400 focus:border-red-500 focus:ring-red-500/20';
@endphp

    <div class="login-card w-full max-w-sm">
        <img src="{{ asset('images/marcomedia-logo-transparent.png') }}" alt="Marcomedia: the best place to get customized" class="h-32 sm:h-40 w-auto mx-auto mb-6">

        <div class="bg-white rounded-2xl shadow-xl p-8" x-data="{ recovery: @js($useRecovery) }">
            <div class="text-center mb-6">
                <div class="w-12 h-12 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center mx-auto mb-3">
                    <i data-lucide="smartphone" class="w-6 h-6"></i>
                </div>
                <p class="text-lg font-semibold text-ink">Two-step verification</p>
                <p class="text-sm text-slate-500 mt-1" x-show="!recovery">
                    Open your authenticator app and type the 6-digit code it shows for <span class="font-medium text-slate-700">Marcomedia POS</span>.
                </p>
                <p class="text-sm text-slate-500 mt-1" x-show="recovery" style="display:none;">
                    Lost your phone? Type one of the recovery codes you saved when you set this up.
                </p>
            </div>

            <form id="code-form" method="POST" action="{{ route('two-factor.login') }}" class="space-y-4">
                @csrf
                <input type="hidden" name="use_recovery" :value="recovery ? 1 : 0">

                <div x-show="!recovery">
                    <label for="code" class="sr-only">6-digit code</label>
                    <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code"
                           :disabled="recovery" {{ $useRecovery ? '' : 'autofocus' }} placeholder="123 456"
                           class="{{ $field }} text-2xl tracking-[0.35em] font-semibold {{ $errors->has('code') ? $bad : $ok }}">
                    @error('code')
                        <p class="text-xs text-red-500 mt-1.5 text-center">{{ $message }}</p>
                    @enderror
                </div>

                <div x-show="recovery" style="display:none;">
                    <label for="recovery_code" class="sr-only">Recovery code</label>
                    <input id="recovery_code" type="text" name="recovery_code" autocomplete="off" autocapitalize="none" spellcheck="false"
                           :disabled="!recovery" {{ $useRecovery ? 'autofocus' : '' }} placeholder="abcde-fghjk"
                           class="{{ $field }} text-lg tracking-wider font-mono {{ $errors->has('recovery_code') ? $bad : $ok }}">
                    @error('recovery_code')
                        <p class="text-xs text-red-500 mt-1.5 text-center">{{ $message }}</p>
                    @enderror
                </div>

                <button type="submit" class="w-full h-11 bg-ink hover:bg-slate-800 text-white text-sm font-medium rounded-lg">
                    Verify and log in
                </button>
            </form>

            <div class="mt-5 flex flex-col items-center gap-2 text-sm">
                <button type="button" class="text-brand-600 hover:underline" x-show="!recovery"
                        @click="recovery = true; $nextTick(() => document.getElementById('recovery_code').focus())">
                    Use a recovery code instead
                </button>
                <button type="button" class="text-brand-600 hover:underline" x-show="recovery" style="display:none;"
                        @click="recovery = false; $nextTick(() => document.getElementById('code').focus())">
                    Use the code from my app
                </button>
                <a href="{{ route('login') }}" class="text-slate-500 hover:underline">Back to log in</a>
            </div>
        </div>
    </div>

    <script>
    lucide.createIcons();

    // Same as the login page: the brand screen fades in, then the form is sent.
    document.getElementById('code-form').addEventListener('submit', function (e) {
        if (this.dataset.sending) return;
        e.preventDefault();
        this.dataset.sending = '1';
        const button = this.querySelector('button[type=submit]');
        button.disabled = true;
        button.textContent = 'Checking…';
        authSplash.show('Signing in…');
        setTimeout(() => this.submit(), 300);
    });
    window.addEventListener('pageshow', (e) => {
        if (!e.persisted) return;
        const form = document.getElementById('code-form');
        delete form.dataset.sending;
        const button = form.querySelector('button[type=submit]');
        button.disabled = false;
        button.textContent = 'Verify and log in';
    });
    </script>
</body>
</html>
