@extends('layouts.app')
@section('title', 'My Profile')

@section('content')
<div class="max-w-2xl mx-auto space-y-6">

    {{-- Avatar + basic info header --}}
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6 flex items-center gap-4">
        <div class="w-16 h-16 rounded-full bg-brand-100 text-brand-700 flex items-center justify-center text-xl font-semibold">
            {{ collect(explode(' ', auth()->user()->name))->map(fn($p) => strtoupper($p[0] ?? ''))->join('') }}
        </div>
        <div>
            <p class="text-lg font-semibold text-ink dark:text-white">{{ auth()->user()->name }}</p>
            <p class="text-sm text-slate-500">{{ auth()->user()->email }}</p>
            <p class="text-xs text-brand-600 uppercase tracking-wide font-medium mt-1">{{ auth()->user()->role ?? 'User' }}</p>
        </div>
    </div>

    {{-- Profile information --}}
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
        <p class="font-semibold text-ink dark:text-white mb-1">Profile Information</p>
        <p class="text-xs text-slate-500 mb-5">Update your account's name and email address.</p>

        <form method="POST" action="{{ route('profile.update') }}" class="space-y-4">
            @csrf
            @method('PATCH')

            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Name</label>
                <input type="text" name="name" value="{{ old('name', auth()->user()->name) }}" required
                       class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                @error('name')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Email</label>
                <input type="email" name="email" value="{{ old('email', auth()->user()->email) }}" required
                       class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                @error('email')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>

            <button type="submit" class="bg-brand-600 text-white text-sm rounded-lg px-5 py-2.5">Save</button>
            @if (session('status') === 'profile-updated')
                <span class="text-xs text-emerald-600 ml-2">Saved.</span>
            @endif
        </form>
    </div>

    {{-- Password update --}}
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
        <p class="font-semibold text-ink dark:text-white mb-1">Update Password</p>
        <p class="text-xs text-slate-500 mb-5">Use a long, random password to keep your account secure.</p>

        <form method="POST" action="{{ route('password.update') }}" class="space-y-4" x-data="{ show: false }">
            @csrf
            @method('PUT')

            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Current Password</label>
                <input :type="show ? 'text' : 'password'" name="current_password"
                       class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                @error('current_password', 'updatePassword')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">New Password</label>
                <input :type="show ? 'text' : 'password'" name="password"
                       class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
                @error('password', 'updatePassword')<p class="text-xs text-red-500 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Confirm New Password</label>
                <input :type="show ? 'text' : 'password'" name="password_confirmation"
                       class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
            </div>
            <label class="flex items-center gap-2 text-xs text-slate-500 dark:text-slate-400">
                <input type="checkbox" x-model="show" class="rounded border-slate-300"> Show passwords
            </label>

            <button type="submit" class="bg-ink hover:bg-slate-800 text-white text-sm rounded-lg px-5 py-2.5">Update Password</button>
        </form>
    </div>
</div>
@endsection
