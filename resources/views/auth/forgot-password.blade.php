@php
    // The login page's email box (resources/views/auth/login.blade.php).
    $field = 'block w-full h-11 rounded-lg border bg-white pl-10 pr-3.5 text-sm text-ink placeholder:text-slate-400 shadow-sm focus:outline-none focus:ring-2 transition';
    $ok = 'border-slate-300 focus:border-brand-500 focus:ring-brand-500/20';
    $bad = 'border-red-400 focus:border-red-500 focus:ring-red-500/20';
@endphp
<x-guest-layout title="Forgot your password?">
    <div class="text-center mb-6">
        <div class="w-12 h-12 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center mx-auto mb-3">
            <i data-lucide="key-round" class="w-6 h-6"></i>
        </div>
        <p class="text-lg font-semibold text-ink">Forgot your password?</p>
        <p class="text-sm text-slate-500 mt-1">Type the email you log in with. We'll send a link to set a new password.</p>
    </div>

    @if (session('status'))
        <div class="mb-4 flex gap-2.5 rounded-lg border border-emerald-200 bg-emerald-50 px-3.5 py-3 text-sm text-emerald-800">
            <i data-lucide="mail-check" class="w-4 h-4 mt-0.5 flex-shrink-0"></i>
            <span>{{ session('status') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4">
        @csrf

        <div>
            <label for="email" class="block mb-1.5 text-xs font-medium text-slate-600">Email</label>
            <div class="relative">
                <i data-lucide="mail" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="username"
                       placeholder="you@example.com" class="{{ $field }} {{ $errors->has('email') ? $bad : $ok }}">
            </div>
            @error('email')
                <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>
            @enderror
        </div>

        <button type="submit" class="w-full h-11 bg-ink hover:bg-slate-800 text-white text-sm font-medium rounded-lg">
            Email me a link
        </button>
    </form>

    <p class="mt-5 text-xs text-slate-500 leading-relaxed">
        The link only goes to an email that was confirmed in Profile. Never confirmed yours? Ask the owner to set a new password for you in User Management.
    </p>

    <div class="mt-5 text-center text-sm">
        <a href="{{ route('login') }}" class="text-slate-500 hover:underline">Back to log in</a>
    </div>
</x-guest-layout>
