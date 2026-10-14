@php
    // The login page's boxes, and the code page's (auth/two-factor-challenge).
    $field = 'block w-full h-11 rounded-lg border bg-white pl-10 text-sm text-ink placeholder:text-slate-400 shadow-sm focus:outline-none focus:ring-2 transition';
    $codeField = 'block w-full h-12 rounded-lg border bg-white text-center text-ink shadow-sm focus:outline-none focus:ring-2 transition';
    $ok = 'border-slate-300 focus:border-brand-500 focus:ring-brand-500/20';
    $bad = 'border-red-400 focus:border-red-500 focus:ring-red-500/20';
    $useRecovery = (bool) old('use_recovery');
@endphp
<x-guest-layout title="Set a new password">
    <div x-data="{ show: false, recovery: @js($useRecovery) }">
        <div class="text-center mb-6">
            <div class="w-12 h-12 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center mx-auto mb-3">
                <i data-lucide="lock-keyhole" class="w-6 h-6"></i>
            </div>
            <p class="text-lg font-semibold text-ink">Set a new password</p>
            <p class="text-sm text-slate-500 mt-1">For <span class="font-medium text-slate-700 break-all">{{ $email }}</span></p>
        </div>

        <form id="reset-form" method="POST" action="{{ route('password.store') }}" class="space-y-4">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email }}">
            {{-- For the browser's password manager: which account the new password belongs to --}}
            <input type="text" value="{{ $email }}" autocomplete="username" class="sr-only" tabindex="-1" aria-hidden="true" readonly>

            <div>
                <label for="password" class="block mb-1.5 text-xs font-medium text-slate-600">New password</label>
                <div class="relative">
                    <i data-lucide="lock" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                    <input id="password" :type="show ? 'text' : 'password'" type="password" name="password" required {{ $errors->hasAny(['code', 'recovery_code']) ? '' : 'autofocus' }} autocomplete="new-password"
                           placeholder="At least 8 characters" class="{{ $field }} pr-11 {{ $errors->has('password') ? $bad : $ok }}">
                    <button type="button" @click="show = !show" :aria-label="show ? 'Hide passwords' : 'Show passwords'"
                            class="absolute inset-y-0 right-0 w-11 flex items-center justify-center text-slate-400 hover:text-slate-600">
                        <i data-lucide="eye" class="w-4 h-4" x-show="!show"></i>
                        <i data-lucide="eye-off" class="w-4 h-4" x-show="show" style="display:none;"></i>
                    </button>
                </div>
                @error('password')
                    <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block mb-1.5 text-xs font-medium text-slate-600">Type it again</label>
                <div class="relative">
                    <i data-lucide="lock" class="w-4 h-4 text-slate-400 absolute left-3.5 top-1/2 -translate-y-1/2 pointer-events-none"></i>
                    <input id="password_confirmation" :type="show ? 'text' : 'password'" type="password" name="password_confirmation" required autocomplete="new-password"
                           placeholder="The same password" class="{{ $field }} pr-3.5 {{ $ok }}">
                </div>
            </div>

            @if ($needsCode)
                {{-- Two-step verification is on: the emailed link alone doesn't open the account --}}
                <input type="hidden" name="use_recovery" :value="recovery ? 1 : 0">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-4 space-y-3">
                    <p class="text-sm text-slate-600" x-show="!recovery">
                        Your account uses two-step verification. Type the 6-digit code your authenticator app shows for <span class="font-medium text-slate-700">Marcomedia POS</span>.
                    </p>
                    <p class="text-sm text-slate-600" x-show="recovery" style="display:none;">
                        Type one of the recovery codes you saved when you set up two-step verification.
                    </p>

                    <div x-show="!recovery">
                        <label for="code" class="sr-only">6-digit code</label>
                        <input id="code" type="text" name="code" inputmode="numeric" pattern="[0-9 ]*" maxlength="7" autocomplete="one-time-code"
                               :disabled="recovery" {{ $errors->has('code') ? 'autofocus' : '' }} placeholder="123 456"
                               class="{{ $codeField }} text-2xl tracking-[0.35em] font-semibold {{ $errors->has('code') ? $bad : $ok }}">
                        @error('code')
                            <p class="text-xs text-red-500 mt-1.5 text-center">{{ $message }}</p>
                        @enderror
                    </div>

                    <div x-show="recovery" style="display:none;">
                        <label for="recovery_code" class="sr-only">Recovery code</label>
                        <input id="recovery_code" type="text" name="recovery_code" autocomplete="off" autocapitalize="none" spellcheck="false"
                               :disabled="!recovery" {{ $errors->has('recovery_code') ? 'autofocus' : '' }} placeholder="abcde-fghjk"
                               class="{{ $codeField }} text-lg tracking-wider font-mono {{ $errors->has('recovery_code') ? $bad : $ok }}">
                        @error('recovery_code')
                            <p class="text-xs text-red-500 mt-1.5 text-center">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="text-center text-sm">
                        <button type="button" class="text-brand-600 hover:underline" x-show="!recovery"
                                @click="recovery = true; $nextTick(() => document.getElementById('recovery_code').focus())">
                            Use a recovery code instead
                        </button>
                        <button type="button" class="text-brand-600 hover:underline" x-show="recovery" style="display:none;"
                                @click="recovery = false; $nextTick(() => document.getElementById('code').focus())">
                            Use the code from my app
                        </button>
                    </div>
                </div>
            @endif

            <button type="submit" class="w-full h-11 bg-ink hover:bg-slate-800 text-white text-sm font-medium rounded-lg">
                Save and log in
            </button>
        </form>

        @if ($needsCode)
            <p class="mt-5 text-xs text-slate-500 leading-relaxed">
                Lost your phone and your recovery codes too? Ask the owner to set a new password and turn off two-step verification for you in User Management.
            </p>
        @endif

        <div class="mt-5 text-center text-sm">
            <a href="{{ route('login') }}" class="text-slate-500 hover:underline">Back to log in</a>
        </div>
    </div>

    <x-slot:scripts>
        <script>
        // Like the login page: the brand screen fades in, then the form is sent, and the first page
        // of the app fades it away.
        document.getElementById('reset-form').addEventListener('submit', function (e) {
            if (this.dataset.sending) return;
            e.preventDefault();
            this.dataset.sending = '1';
            const button = this.querySelector('button[type=submit]');
            button.disabled = true;
            button.textContent = 'Saving…';
            authSplash.show('Signing in…');
            setTimeout(() => this.submit(), 300);
        });
        window.addEventListener('pageshow', (e) => {
            if (!e.persisted) return;
            const form = document.getElementById('reset-form');
            delete form.dataset.sending;
            const button = form.querySelector('button[type=submit]');
            button.disabled = false;
            button.textContent = 'Save and log in';
        });
        </script>
    </x-slot:scripts>
</x-guest-layout>
