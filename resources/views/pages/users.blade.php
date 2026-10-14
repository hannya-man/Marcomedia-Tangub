@extends('layouts.app')
@section('title', 'User Management')
@section('shows-errors', '1')

@section('content')
@php
    // Deleting a cashier is confirmed with the owner's authenticator code; a wrong code reopens it.
    $deleteAgain = $errors->deleteUser->any() ? $users->firstWhere('id', (int) old('delete_user_id')) : null;
@endphp
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6"
     x-data="{ deleting: @js($deleteAgain ? ['id' => $deleteAgain->id, 'name' => $deleteAgain->name, 'url' => route('users.destroy', $deleteAgain)] : null) }">
    <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="flex items-center justify-between p-4 border-b border-slate-100">
            <p class="font-medium text-ink">All Users</p>
            <form method="GET" id="users-search-form" class="relative">
                <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                <input type="text" name="search" id="users-search-input" value="{{ request('search') }}" placeholder="Search user..." autocomplete="off"
                       class="text-sm rounded-full border border-slate-300 bg-white pl-9 pr-8 py-2 shadow-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                <i id="users-search-spinner" data-lucide="loader-2" class="w-3.5 h-3.5 text-brand-500 absolute right-3 top-1/2 -translate-y-1/2 animate-spin" style="display:none;"></i>
            </form>
        </div>

        <script>
        (function () {
            const input = document.getElementById('users-search-input');
            const form = document.getElementById('users-search-form');
            const spinner = document.getElementById('users-search-spinner');
            let debounceTimer;

            input.addEventListener('input', function () {
                clearTimeout(debounceTimer);
                spinner.style.display = 'block';
                debounceTimer = setTimeout(function () {
                    form.submit();
                }, 400);
            });
        })();
        </script>
        <div class="overflow-x-auto">
<table class="w-full text-sm">
            <thead><tr class="text-left text-slate-500 bg-slate-50 border-b border-slate-200">
                <th class="p-4">Name</th><th>Email</th><th>Role</th><th class="whitespace-nowrap" title="Two-step verification: a code from an authenticator app after the password">2-step</th><th></th>
            </tr></thead>
            <tbody>
                @forelse($users as $u)
                <tr class="border-b border-slate-50 {{ $editingUser && $editingUser->id === $u->id ? 'bg-brand-50' : '' }}">
                    <td class="p-4 flex items-center gap-2">
                        <div class="w-7 h-7 rounded-full bg-brand-100 text-brand-700 text-xs flex items-center justify-center font-medium">
                            {{ collect(explode(' ', $u->name))->map(fn($p) => strtoupper($p[0] ?? ''))->join('') }}
                        </div>
                        {{ $u->name }}
                        @if($u->id === auth()->id())<span class="text-[10px] text-slate-400">(you)</span>@endif
                    </td>
                    <td class="text-slate-500">
                        {{-- Confirmed in Profile: a forgotten password or a lost phone can be fixed by email --}}
                        <span class="inline-flex items-center gap-1">
                            {{ $u->email }}
                            @if($u->hasVerifiedEmail())
                                <span class="inline-flex flex-shrink-0 text-emerald-600" title="Email confirmed: they can get a link to log back in">
                                    <i data-lucide="mail-check" class="w-3.5 h-3.5"></i><span class="sr-only">(confirmed)</span>
                                </span>
                            @endif
                        </span>
                        @if(!$u->hasVerifiedEmail() && $emailRecovery)
                            <span class="block text-[11px] text-slate-400">Not confirmed yet</span>
                        @endif
                    </td>
                    <td class="capitalize">{{ $u->role }}</td>
                    <td>
                        @if($u->hasTwoFactor())
                            <span class="inline-flex items-center gap-1 text-xs font-medium text-emerald-700"><i data-lucide="shield-check" class="w-3.5 h-3.5"></i> On</span>
                        @else
                            <span class="text-xs text-slate-400">Off</span>
                        @endif
                    </td>
                    <td class="pr-4">
                        <div class="flex items-center justify-end gap-1.5">
                            <a href="{{ route('users.index', ['edit' => $u->id]) }}"
                               class="inline-flex items-center gap-1 text-xs bg-brand-50 text-brand-700 hover:bg-brand-100 rounded-lg px-2.5 py-1.5">
                                <i data-lucide="pencil" class="w-3 h-3"></i> Edit
                            </a>
                            {{-- Only cashiers' accounts are deleted, never your own --}}
                            @if($u->role === 'cashier' && $u->id !== auth()->id())
                                <button type="button" @click="deleting = @js(['id' => $u->id, 'name' => $u->name, 'url' => route('users.destroy', $u)])"
                                        class="inline-flex items-center gap-1 text-xs border border-red-200 text-red-600 hover:bg-red-50 rounded-lg px-2.5 py-1.5">
                                    <i data-lucide="trash-2" class="w-3 h-3"></i> Delete
                                </button>
                            @endif
                        </div>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="p-8 text-center text-sm text-slate-400">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
</div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <p class="font-medium text-ink mb-4">
            {{ $editingUser ? 'Edit ' . $editingUser->name : 'Add User' }}
        </p>
        <div class="w-16 h-16 rounded-full bg-brand-100 text-brand-700 mx-auto mb-4 flex items-center justify-center text-lg font-semibold">
            {{ $editingUser ? collect(explode(' ', $editingUser->name))->map(fn($p) => strtoupper($p[0] ?? ''))->join('') : '+' }}
        </div>

        <form method="POST" action="{{ $editingUser ? route('users.update', $editingUser) : route('users.store') }}" class="space-y-3" x-data="{ showPassword: false }">
            @csrf
            @if($editingUser) @method('PATCH') @endif

            <div>
                <label class="text-xs font-medium text-slate-600">Full Name</label>
                <input type="text" name="name" required value="{{ old('name', $editingUser->name ?? '') }}"
                       class="mt-1 w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
            </div>
            <div>
                <label class="text-xs font-medium text-slate-600">Email</label>
                <input type="email" name="email" required value="{{ old('email', $editingUser->email ?? '') }}"
                       class="mt-1 w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                @if($emailRecovery)
                    <p class="text-[11px] text-slate-400 mt-1">Use their real email. They confirm it in their Profile, so they can get a link if they forget their password.</p>
                @endif
            </div>
            <div>
                <label class="text-xs font-medium text-slate-600">Role</label>
                <div class="mt-1">
                    <x-select-menu name="role"
                        :options="['admin' => 'Administrator', 'cashier' => 'Cashier']"
                        :selected="old('role', $editingUser->role ?? 'cashier')" />
                </div>
            </div>
            <div>
                <label class="text-xs font-medium text-slate-600">
                    Password {{ $editingUser ? '(leave blank to keep current)' : '' }}
                </label>
                <div class="relative mt-1">
                    <input :type="showPassword ? 'text' : 'password'" name="password" {{ $editingUser ? '' : 'required' }}
                           class="w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 pr-10 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                    <button type="button" @click="showPassword = !showPassword"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600">
                        <i data-lucide="eye" class="w-4 h-4" x-show="!showPassword"></i>
                        <i data-lucide="eye-off" class="w-4 h-4" x-show="showPassword" style="display:none;"></i>
                    </button>
                </div>
            </div>

            @foreach(collect($errors->all())->unique() as $message)
                <p class="text-xs text-red-500">{{ $message }}</p>
            @endforeach

            <button type="submit" class="w-full bg-ink hover:bg-slate-800 text-white text-sm rounded-lg py-2.5 mt-2 flex items-center justify-center gap-1.5">
                <i data-lucide="{{ $editingUser ? 'check' : 'user-plus' }}" class="w-4 h-4"></i>
                {{ $editingUser ? 'Save Changes' : 'Save User' }}
            </button>
            @if($editingUser)
                <a href="{{ route('users.index') }}" class="block text-center text-xs text-slate-500 mt-1">Cancel edit</a>
            @endif
        </form>

        {{-- Someone lost the phone with their authenticator app: turn theirs off, so they can log in
             with the password and set it up again. Confirmed with your own code. --}}
        @if($editingUser && $editingUser->hasTwoFactor() && $editingUser->id !== auth()->id())
            <div class="mt-6 pt-5 border-t border-slate-100" x-data="{ open: @js($errors->resetTwoFactor->any()) }">
                <p class="text-xs font-medium text-slate-600 flex items-center gap-1.5"><i data-lucide="shield-check" class="w-3.5 h-3.5 text-emerald-600"></i> Two-step verification is on</p>
                <button type="button" x-show="!open" @click="open = true" class="mt-2 text-xs text-red-600 hover:underline">
                    Turn it off for {{ $editingUser->name }} (lost phone)
                </button>
                <form x-show="open" style="display:none;" method="POST" action="{{ route('users.two-factor.reset', $editingUser) }}" class="mt-2 space-y-2">
                    @csrf
                    @method('DELETE')
                    <p class="text-xs text-slate-500">{{ $editingUser->name }} logs in with the password only until they set it up again{{ $editingUser->isAdmin() ? ' (as an owner, right after logging in)' : '' }}.</p>
                    <label class="block text-xs font-medium text-slate-600">Your 6-digit code, to confirm</label>
                    <div class="flex gap-2">
                        <input type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123 456"
                               class="w-32 text-sm text-center tracking-widest rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                        <button type="submit" class="text-xs font-medium bg-red-600 hover:bg-red-700 text-white rounded-lg px-3">Turn off</button>
                    </div>
                    @foreach($errors->resetTwoFactor->all() as $message)
                        <p class="text-xs text-red-500">{{ $message }}</p>
                    @endforeach
                </form>
            </div>
        @endif
    </div>

    {{-- Delete a cashier's account: confirmed with your authenticator app's code --}}
    <div x-show="deleting !== null" style="display:none;" x-transition.opacity
         class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="deleting = null" @keydown.escape.window="deleting = null">
        <form x-show="deleting !== null" method="POST" :action="deleting ? deleting.url : ''"
              class="bg-white rounded-lg shadow-lg border border-slate-200 w-full max-w-md p-6">
            @csrf
            @method('DELETE')
            <input type="hidden" name="delete_user_id" :value="deleting ? deleting.id : ''">
            <p class="text-lg font-semibold text-ink mb-2">Delete <span x-text="deleting?.name"></span>'s account?</p>
            <p class="text-sm text-slate-500">
                They won't be able to log in any more. Their past sales and stock records keep their name. This can't be undone
                here: to work with them again, add a new account.
            </p>
            <div class="mt-5 rounded-lg bg-slate-50 p-4">
                <label for="delete-code" class="flex items-center gap-1.5 text-sm font-medium text-ink">
                    <i data-lucide="smartphone" class="w-4 h-4 text-brand-600"></i> Type the 6-digit code from your authenticator app
                </label>
                <input id="delete-code" type="text" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="7" placeholder="123 456"
                       x-effect="if (deleting) $nextTick(() => $el.focus())"
                       class="mt-2 w-40 h-11 rounded-lg border border-slate-300 bg-white text-center text-lg font-semibold tracking-[0.3em] focus:outline-none focus:ring-2 focus:ring-red-500/20 focus:border-red-500">
                @foreach($errors->deleteUser->all() as $message)
                    <p class="text-xs text-red-500 mt-1.5">{{ $message }}</p>
                @endforeach
                <p class="text-xs text-slate-400 mt-2">For security, deleting an account always asks for it.</p>
            </div>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 mt-6">
                <button type="button" @click="deleting = null" class="border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-md px-4 py-2">Cancel</button>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">Delete account</button>
            </div>
        </form>
    </div>
</div>
@endsection
