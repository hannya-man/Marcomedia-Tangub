<!DOCTYPE html>
<html lang="en" x-data="{ dark: localStorage.getItem('darkMode') === 'true' }" :class="{ 'dark': dark }">
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
    <script>
        if (localStorage.getItem('darkMode') === 'true') { document.documentElement.classList.add('dark'); }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@latest"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
</head>
<body class="bg-gradient-to-br from-ink via-slate-900 to-brand-700 min-h-screen flex items-center justify-center p-4">

    <div class="login-card w-full max-w-sm bg-white dark:bg-slate-800 rounded-2xl shadow-xl p-8" x-data="{ showPassword: false }">
        <div class="text-center mb-8">
            <div class="w-14 h-14 rounded-xl bg-gold-500 mx-auto flex items-center justify-center text-ink font-bold text-xl mb-3">M</div>
            <p class="text-lg font-semibold text-ink dark:text-white">Marcomedia POS</p>
            <p class="text-xs text-slate-500 dark:text-slate-400 uppercase tracking-wider mt-1">Printing &amp; Photography</p>
        </div>

        {{-- Session status (e.g. password reset confirmations, if you re-add that later) --}}
        @if (session('status'))
            <div class="mb-4 text-sm text-emerald-600 text-center">{{ session('status') }}</div>
        @endif

        <form method="POST" action="{{ route('login') }}" class="space-y-4">
            @csrf

            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       class="mt-1 w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white text-sm focus:ring-brand-500 focus:border-brand-500"
                       placeholder="you@example.com">
                @error('email')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Password</label>
                <div class="relative mt-1">
                    <input :type="showPassword ? 'text' : 'password'" name="password" required autocomplete="current-password"
                           class="w-full rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white text-sm focus:ring-brand-500 focus:border-brand-500 pr-10"
                           placeholder="••••••••">
                    <button type="button" @click="showPassword = !showPassword"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600">
                        <i data-lucide="eye" class="w-4 h-4" x-show="!showPassword"></i>
                        <i data-lucide="eye-off" class="w-4 h-4" x-show="showPassword" style="display:none;"></i>
                    </button>
                </div>
                @error('password')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <input type="checkbox" name="remember" class="rounded border-slate-300"> Remember me
            </label>

            <button type="submit" class="w-full bg-ink hover:bg-slate-800 text-white text-sm font-medium rounded-lg py-2.5">
                Log In
            </button>
        </form>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>
