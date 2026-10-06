@extends('layouts.app')
@section('title', 'User Management')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="flex items-center justify-between p-4 border-b border-slate-100 dark:border-slate-700">
            <p class="font-medium text-ink dark:text-white">All Users</p>
            <form method="GET" id="users-search-form" class="relative">
                <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                <input type="text" name="search" id="users-search-input" value="{{ request('search') }}" placeholder="Search user..." autocomplete="off"
                       class="text-sm rounded-full border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white pl-9 pr-8 py-2 shadow-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
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
            <thead><tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
                <th class="p-4">Name</th><th>Email</th><th>Role</th><th></th>
            </tr></thead>
            <tbody>
                @forelse($users as $u)
                <tr class="border-b border-slate-50 dark:border-slate-700 {{ $editingUser && $editingUser->id === $u->id ? 'bg-brand-50 dark:bg-brand-600/10' : '' }}">
                    <td class="p-4 flex items-center gap-2">
                        <div class="w-7 h-7 rounded-full bg-brand-100 text-brand-700 dark:bg-brand-600 dark:text-white text-xs flex items-center justify-center font-medium">
                            {{ collect(explode(' ', $u->name))->map(fn($p) => strtoupper($p[0] ?? ''))->join('') }}
                        </div>
                        {{ $u->name }}
                        @if($u->id === auth()->id())<span class="text-[10px] text-slate-400">(you)</span>@endif
                    </td>
                    <td class="text-slate-500 dark:text-slate-400">{{ $u->email }}</td>
                    <td class="capitalize">{{ $u->role }}</td>
                    <td>
                        <a href="{{ route('users.index', ['edit' => $u->id]) }}"
                           class="inline-flex items-center gap-1 text-xs bg-brand-50 dark:bg-brand-600/20 text-brand-700 dark:text-brand-300 hover:bg-brand-100 dark:hover:bg-brand-600/30 rounded-lg px-2.5 py-1.5">
                            <i data-lucide="pencil" class="w-3 h-3"></i> Edit
                        </a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="4" class="p-8 text-center text-sm text-slate-400">No users found.</td></tr>
                @endforelse
            </tbody>
        </table>
</div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
        <p class="font-medium text-ink dark:text-white mb-4">
            {{ $editingUser ? 'Edit ' . $editingUser->name : 'Add User' }}
        </p>
        <div class="w-16 h-16 rounded-full bg-brand-100 text-brand-700 dark:bg-brand-600 dark:text-white mx-auto mb-4 flex items-center justify-center text-lg font-semibold">
            {{ $editingUser ? collect(explode(' ', $editingUser->name))->map(fn($p) => strtoupper($p[0] ?? ''))->join('') : '+' }}
        </div>

        <form method="POST" action="{{ $editingUser ? route('users.update', $editingUser) : route('users.store') }}" class="space-y-3" x-data="{ showPassword: false }">
            @csrf
            @if($editingUser) @method('PATCH') @endif

            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Full Name</label>
                <input type="text" name="name" required value="{{ old('name', $editingUser->name ?? '') }}"
                       class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
            </div>
            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Email</label>
                <input type="email" name="email" required value="{{ old('email', $editingUser->email ?? '') }}"
                       class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
            </div>
            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Role</label>
                <div class="mt-1">
                    <x-select-menu name="role"
                        :options="['admin' => 'Administrator', 'cashier' => 'Cashier']"
                        :selected="old('role', $editingUser->role ?? 'cashier')" />
                </div>
            </div>
            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">
                    Password {{ $editingUser ? '(leave blank to keep current)' : '' }}
                </label>
                <div class="relative mt-1">
                    <input :type="showPassword ? 'text' : 'password'" name="password" {{ $editingUser ? '' : 'required' }}
                           class="w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 pr-10 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                    <button type="button" @click="showPassword = !showPassword"
                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 dark:hover:text-slate-300">
                        <i data-lucide="eye" class="w-4 h-4" x-show="!showPassword"></i>
                        <i data-lucide="eye-off" class="w-4 h-4" x-show="showPassword" style="display:none;"></i>
                    </button>
                </div>
            </div>

            @error('email')<p class="text-xs text-red-500">{{ $message }}</p>@enderror
            @error('password')<p class="text-xs text-red-500">{{ $message }}</p>@enderror

            <button type="submit" class="w-full bg-ink hover:bg-slate-800 dark:bg-white dark:hover:bg-slate-200 text-white dark:text-ink text-sm rounded-lg py-2.5 mt-2 flex items-center justify-center gap-1.5">
                <i data-lucide="{{ $editingUser ? 'check' : 'user-plus' }}" class="w-4 h-4"></i>
                {{ $editingUser ? 'Save Changes' : 'Save User' }}
            </button>
            @if($editingUser)
                <a href="{{ route('users.index') }}" class="block text-center text-xs text-slate-500 dark:text-slate-400 mt-1">Cancel edit</a>
            @endif
        </form>
    </div>
</div>
@endsection
