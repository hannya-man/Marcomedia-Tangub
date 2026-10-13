@extends('layouts.app')
@section('title', 'Two-step verification')
@section('shows-errors', '1')

@section('content')
@php
    $card = 'bg-white rounded-xl border border-slate-200 p-6';
    $codeInput = 'w-40 h-11 rounded-lg border border-slate-300 bg-white text-center text-lg font-semibold tracking-[0.3em] focus:outline-none focus:ring-2 focus:ring-brand-500/25 focus:border-brand-500';
    $step = 'flex-shrink-0 w-7 h-7 rounded-full bg-brand-600 text-white text-sm font-semibold flex items-center justify-center';
    $mustHaveIt = $user->isAdmin() && ! $user->hasTwoFactor();
@endphp
<div class="max-w-2xl mx-auto space-y-6">

    @if($mustHaveIt)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 flex gap-3">
            <i data-lucide="shield-alert" class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5"></i>
            <div class="text-sm text-amber-900">
                <p class="font-semibold">Owner accounts must use two-step verification.</p>
                <p class="mt-0.5">Set it up below to keep using Marcomedia POS. It takes about two minutes, with your phone.</p>
            </div>
        </div>
    @endif

    {{-- Recovery codes just made: shown this once --}}
    @if($newCodes)
        <div class="rounded-xl border-2 border-brand-500 bg-white p-6" x-data="{ copied: false }">
            <div class="flex items-start gap-3 mb-4">
                <i data-lucide="key-round" class="w-5 h-5 text-brand-600 flex-shrink-0 mt-0.5"></i>
                <div>
                    <p class="font-semibold text-ink">Save these recovery codes now</p>
                    <p class="text-sm text-slate-500 mt-0.5">
                        If you lose your phone, each code logs you in once instead of the app. Write them down or
                        download them, and keep them somewhere safe, not on the same phone. They won't be shown again.
                    </p>
                </div>
            </div>
            <div id="recovery-codes" class="grid grid-cols-2 gap-2 rounded-lg bg-slate-50 p-4 font-mono text-base text-ink text-center">
                @foreach($newCodes as $code)
                    <span>{{ $code }}</span>
                @endforeach
            </div>
            <div class="flex flex-wrap gap-2 mt-4">
                <button type="button" @click="navigator.clipboard.writeText(@js(implode("\n", $newCodes))); copied = true; setTimeout(() => copied = false, 2000)"
                        class="inline-flex items-center gap-1.5 text-sm border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-3 py-2">
                    <i data-lucide="copy" class="w-4 h-4"></i> <span x-text="copied ? 'Copied' : 'Copy'"></span>
                </button>
                <a download="marcomedia-recovery-codes.txt"
                   href="data:text/plain;charset=utf-8,{{ rawurlencode("Marcomedia POS recovery codes for {$user->email}\nEach code works once.\n\n" . implode("\n", $newCodes) . "\n") }}"
                   class="inline-flex items-center gap-1.5 text-sm border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-3 py-2">
                    <i data-lucide="download" class="w-4 h-4"></i> Download
                </a>
                <button type="button" onclick="window.print()"
                        class="inline-flex items-center gap-1.5 text-sm border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-3 py-2">
                    <i data-lucide="printer" class="w-4 h-4"></i> Print
                </button>
            </div>
        </div>
    @endif

    @if($user->hasTwoFactor())
        {{-- ============ On ============ --}}
        <div class="{{ $card }}" x-data="{ action: @js(old('action')) }">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center flex-shrink-0">
                    <i data-lucide="shield-check" class="w-5 h-5"></i>
                </div>
                <div>
                    <p class="font-semibold text-ink">Two-step verification is on</p>
                    <p class="text-sm text-slate-500 mt-0.5">
                        Since {{ $user->two_factor_confirmed_at->format('M j, Y') }}. Logging in asks for your password, then the code from your authenticator app.
                    </p>
                    <p class="text-sm mt-2 {{ $codesLeft <= 2 ? 'text-amber-700' : 'text-slate-500' }}">
                        {{ $codesLeft }} of 8 recovery codes left{{ $codesLeft <= 2 ? ': make new ones soon.' : '.' }}
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 mt-5 pt-5 border-t border-slate-100">
                <button type="button" @click="action = 'codes'" :class="action === 'codes' ? 'bg-slate-100' : ''"
                        class="inline-flex items-center gap-1.5 text-sm border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-3 py-2">
                    <i data-lucide="key-round" class="w-4 h-4"></i> Make new recovery codes
                </button>
                <button type="button" @click="action = 'move'" :class="action === 'move' ? 'bg-slate-100' : ''"
                        class="inline-flex items-center gap-1.5 text-sm border border-slate-300 text-slate-700 hover:bg-slate-50 rounded-lg px-3 py-2">
                    <i data-lucide="smartphone" class="w-4 h-4"></i> Move to a new phone
                </button>
                @unless($user->isAdmin())
                    <button type="button" @click="action = 'off'" :class="action === 'off' ? 'bg-red-50' : ''"
                            class="inline-flex items-center gap-1.5 text-sm border border-red-200 text-red-600 hover:bg-red-50 rounded-lg px-3 py-2">
                        <i data-lucide="shield-off" class="w-4 h-4"></i> Turn off
                    </button>
                @endunless
            </div>

            {{-- Each needs the code from the app now --}}
            <form x-show="action" style="display:none;" method="POST"
                  :action="action === 'codes' ? @js(route('two-factor.recovery-codes')) : (action === 'off' ? @js(route('two-factor.disable')) : @js(route('two-factor.begin')))"
                  class="mt-4 rounded-lg bg-slate-50 p-4">
                @csrf
                <input type="hidden" name="_method" :value="action === 'off' ? 'DELETE' : 'POST'">
                <input type="hidden" name="action" :value="action">
                <p class="text-sm text-ink mb-1" x-show="action === 'codes'">Your old recovery codes will stop working.</p>
                <p class="text-sm text-ink mb-1" x-show="action === 'move'">You'll scan a new QR code with the new phone. The old phone's code stops working.</p>
                <p class="text-sm text-ink mb-1" x-show="action === 'off'">Logging in will ask for your password only.</p>
                <label for="confirm-code" class="block text-xs font-medium text-slate-600 mb-1.5">To confirm, type the 6-digit code your app shows now</label>
                <div class="flex flex-wrap items-center gap-2">
                    <input id="confirm-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123 456" class="{{ $codeInput }}">
                    <button type="submit" class="text-sm font-medium text-white rounded-lg px-4 h-11" :class="action === 'off' ? 'bg-red-600 hover:bg-red-700' : 'bg-brand-600 hover:bg-brand-700'">Confirm</button>
                    <button type="button" @click="action = null" class="text-sm text-slate-500 px-2">Cancel</button>
                </div>
                @error('code')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
            </form>
        </div>

    @elseif($settingUp)
        {{-- ============ Setting up: scan or type, then one code ============ --}}
        <div class="{{ $card }}">
            <p class="font-semibold text-ink">Set up your authenticator app</p>
            <p class="text-sm text-slate-500 mt-0.5 mb-6">Have your phone ready. If you don't have an app yet, install <strong>Google Authenticator</strong> or <strong>Microsoft Authenticator</strong> (free).</p>

            <div class="flex gap-3">
                <span class="{{ $step }}">1</span>
                <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium text-ink">In the app, tap <strong>+</strong> (Add), then <strong>Scan a QR code</strong>, and point your phone here:</p>
                    <img src="{{ $qrCode }}" alt="QR code to scan with your authenticator app" class="w-48 h-48 mt-3 rounded-lg border border-slate-200 bg-white p-1">

                    <div class="mt-4 rounded-lg bg-slate-50 p-3" x-data="{ copied: false }">
                        <p class="text-xs text-slate-600">Can't scan? Choose <strong>Enter a setup key</strong> in the app, and type:</p>
                        <dl class="mt-2 grid grid-cols-[auto,1fr] gap-x-3 gap-y-1 text-sm">
                            <dt class="text-slate-500">Account</dt><dd class="text-ink">Marcomedia POS ({{ $user->email }})</dd>
                            <dt class="text-slate-500">Key</dt>
                            <dd class="flex flex-wrap items-center gap-2">
                                <code class="font-mono text-ink tracking-wider break-all">{{ $setupKey }}</code>
                                <button type="button" @click="navigator.clipboard.writeText(@js(str_replace(' ', '', $setupKey))); copied = true; setTimeout(() => copied = false, 2000)"
                                        class="text-xs text-brand-600 hover:underline" x-text="copied ? 'Copied' : 'Copy'"></button>
                            </dd>
                            <dt class="text-slate-500">Type</dt><dd class="text-ink">Time based</dd>
                        </dl>
                        <p class="text-xs text-slate-500 mt-2">The key has only letters A to Z and numbers 2 to 7: an O is always the letter, never zero.</p>
                    </div>
                </div>
            </div>

            <form method="POST" action="{{ route('two-factor.confirm') }}" class="flex gap-3 mt-6">
                @csrf
                <span class="{{ $step }}">2</span>
                <div class="min-w-0 flex-1">
                    <label for="code" class="block text-sm font-medium text-ink mb-2">Type the 6-digit code the app now shows for Marcomedia POS</label>
                    <div class="flex flex-wrap items-center gap-2">
                        <input id="code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123 456" autofocus class="{{ $codeInput }}">
                        <button type="submit" class="text-sm font-medium bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-5 h-11">Turn on</button>
                    </div>
                    @error('code')<p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>@enderror
                    <p class="text-xs text-slate-400 mt-2">The code changes every 30 seconds. If it changes while you type, type the new one.</p>
                </div>
            </form>

            <div class="flex flex-wrap gap-4 mt-6 pt-4 border-t border-slate-100 text-sm">
                <form method="POST" action="{{ route('two-factor.begin') }}">
                    @csrf
                    <button type="submit" class="text-slate-500 hover:underline">Start over with a new QR code</button>
                </form>
                @unless($mustHaveIt)
                    <form method="POST" action="{{ route('two-factor.cancel') }}">
                        @csrf
                        <button type="submit" class="text-slate-500 hover:underline">Cancel</button>
                    </form>
                @endunless
            </div>
        </div>

    @else
        {{-- ============ Off ============ --}}
        <div class="{{ $card }}">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-full bg-brand-50 text-brand-600 flex items-center justify-center flex-shrink-0">
                    <i data-lucide="smartphone" class="w-5 h-5"></i>
                </div>
                <div>
                    <p class="font-semibold text-ink">Protect your account with your phone</p>
                    <p class="text-sm text-slate-500 mt-1">
                        After your password, logging in also asks for a 6-digit code from an app on your phone. The code
                        changes every 30 seconds, so someone who learns your password still can't get in without your phone.
                    </p>
                </div>
            </div>
            <ol class="mt-5 space-y-2 text-sm text-slate-600">
                <li class="flex gap-2"><span class="font-semibold text-ink">1.</span><span>Install <strong>Google Authenticator</strong> or <strong>Microsoft Authenticator</strong> on your phone (free on the Play Store and App Store).</span></li>
                <li class="flex gap-2"><span class="font-semibold text-ink">2.</span><span>Press <strong>Set it up</strong> below, and scan the QR code with the app (or type the key it shows).</span></li>
                <li class="flex gap-2"><span class="font-semibold text-ink">3.</span><span>Type the code from the app to finish, and save your recovery codes.</span></li>
            </ol>
            <form method="POST" action="{{ route('two-factor.begin') }}" class="mt-6">
                @csrf
                <button type="submit" class="inline-flex items-center gap-1.5 text-sm font-medium bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-5 py-2.5">
                    <i data-lucide="shield-check" class="w-4 h-4"></i> Set it up
                </button>
            </form>
        </div>
    @endif

    @unless($mustHaveIt)
        <a href="{{ route('profile.edit') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-ink">
            <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Profile
        </a>
    @endunless
</div>
@endsection
