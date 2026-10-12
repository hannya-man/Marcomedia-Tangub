<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page expired - Marcomedia POS</title>
    @vite(['resources/css/app.css'])
</head>
{{-- Laravel's "419 Page Expired": a form sent from a page left open after the login ended. --}}
<body class="auth-backdrop min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-sm bg-white rounded-2xl shadow-xl p-8 text-center">
        <img src="{{ asset('images/marcomedia-logo-transparent.png') }}" alt="Marcomedia" class="h-20 w-auto mx-auto mb-5 rounded-xl bg-ink p-2">
        <p class="text-lg font-semibold text-ink">This page was open too long</p>
        <p class="text-sm text-slate-500 mt-2">
            For safety, the login ends after a while without use, so what you just sent wasn't saved.
            Log in again, then do it once more.
        </p>
        <a href="{{ url('/') }}" class="mt-6 inline-flex items-center justify-center w-full h-11 rounded-lg bg-ink hover:bg-slate-800 text-white text-sm font-medium">
            Continue
        </a>
    </div>
</body>
</html>
