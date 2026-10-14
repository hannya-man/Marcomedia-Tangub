<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>{{ $title ? $title . ' - ' : '' }}Marcomedia POS</title>
    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#0f172a">
    <link rel="icon" href="/icons/icon-192.png">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://unpkg.com/lucide@1.55.0/dist/umd/lucide.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
    <script>
        // Leaving under the brand screen (logging in from here): no page transition on top of it.
        window.addEventListener('pageswap', (e) => {
            if (e.viewTransition && document.getElementById('auth-splash')?.classList.contains('is-on')) e.viewTransition.skipTransition();
        });
    </script>
</head>
<body class="auth-backdrop min-h-screen flex items-center justify-center p-4">
    @include('partials.auth-splash', ['arriving' => false])

    <div class="login-card w-full max-w-sm">
        <a href="{{ route('login') }}" class="block mb-6">
            <img src="{{ asset('images/marcomedia-logo-transparent.png') }}" alt="Marcomedia: the best place to get customized" class="h-24 sm:h-32 w-auto mx-auto">
        </a>

        <div class="bg-white rounded-2xl shadow-xl p-8">
            {{ $slot }}
        </div>
    </div>

    <script>lucide.createIcons();</script>
    {{ $scripts ?? '' }}
</body>
</html>
