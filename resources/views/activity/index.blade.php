@extends('layouts.app')
@section('title', 'Activity Log')

@section('content')
@php
    $applyFilter = "\$el.closest('form').requestSubmit()";
    $review = request('tab') === 'review';
    $tab = 'px-4 py-2 rounded-lg text-sm font-medium flex items-center gap-2';
@endphp
<div class="space-y-6">
    <p class="text-sm text-slate-500">
        What everyone did and when, cashiers and owners alike: logging in and out, sales, payments, voids, returns, stock changes and account changes.
        <strong class="font-medium text-slate-700">Needs a look</strong> has what an owner should check: wrong passwords, cash differences at the end of a shift,
        voids, returns, and stock written off.
    </p>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="inline-flex gap-1 rounded-xl bg-white border border-slate-200 p-1">
            <a href="{{ route('activity.index', request()->except('tab', 'page')) }}" class="{{ $tab }} {{ $review ? 'text-slate-600 hover:bg-slate-50' : 'bg-brand-600 text-white' }}">Everyone's activity</a>
            <a href="{{ route('activity.index', ['tab' => 'review'] + request()->except('tab', 'page')) }}" class="{{ $tab }} {{ $review ? 'bg-brand-600 text-white' : 'text-slate-600 hover:bg-slate-50' }}">
                Needs a look
                @if($needsReviewCount)<span class="min-w-[1.25rem] h-5 px-1.5 rounded-full text-[11px] font-semibold flex items-center justify-center {{ $review ? 'bg-white text-brand-700' : 'bg-red-500 text-white' }}">{{ $needsReviewCount }}</span>@endif
            </a>
        </div>
        <a href="{{ route('activity.download', request()->except('page')) }}" title="Save what's listed (with these filters) as a spreadsheet, for your records"
           class="text-xs px-4 py-2 border border-slate-300 text-slate-600 rounded-lg flex items-center gap-1.5 bg-white hover:bg-slate-50">
            <i data-lucide="download" class="w-3.5 h-3.5"></i> Download
        </a>
    </div>

    <div class="bg-white rounded-xl border border-slate-200">
        <form method="GET" class="flex flex-wrap gap-3 p-4 border-b border-slate-100">
            @if($review)<input type="hidden" name="tab" value="review">@endif
            <div class="w-56">
                <x-select-menu name="user"
                    :options="collect(['' => 'Everyone'])->union($people->pluck('name', 'id'))->all()"
                    :selected="request('user')"
                    placeholder="Everyone"
                    :onchange="$applyFilter" />
            </div>
            <x-date-picker name="date_from" :selected="request('date_from')" placeholder="From date" :onchange="$applyFilter" />
            <x-date-picker name="date_to" :selected="request('date_to')" placeholder="To date" :onchange="$applyFilter" />
        </form>

        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 border-b border-slate-100">
                    <th class="p-4">When</th><th class="px-2">Who</th><th class="px-2">What happened</th><th class="px-4">From</th>
                </tr>
            </thead>
            <tbody>
                @forelse($logs as $log)
                <tr class="border-b border-slate-50 align-top {{ $log->needs_review ? 'bg-amber-50/60' : '' }}">
                    <td class="p-4 whitespace-nowrap text-slate-500">
                        {{ $log->created_at?->format('M j, Y') }}
                        <span class="block text-xs text-slate-400">{{ $log->created_at?->format('h:i:s A') }}</span>
                    </td>
                    <td class="px-2 py-4 whitespace-nowrap">
                        @if($log->user)
                            <a href="{{ route('activity.index', ['user' => $log->user_id] + request()->except('user', 'page')) }}" class="font-medium text-ink hover:underline" title="Only {{ $log->user->name }}'s activity">{{ $log->user->name }}</a>
                            <span class="block text-xs text-slate-400">{{ $log->user->role === 'admin' ? 'Owner' : 'Cashier' }}</span>
                        @else
                            <span class="text-slate-400">No account</span>
                        @endif
                    </td>
                    <td class="px-2 py-4 text-slate-700">
                        @if($log->needs_review)<i data-lucide="alert-triangle" class="inline w-3.5 h-3.5 text-amber-600 -mt-0.5 mr-1"></i>@endif{{ $log->description }}
                    </td>
                    <td class="px-4 py-4 text-xs text-slate-400 whitespace-nowrap">{{ $log->ip_address ?? '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="4" class="p-8 text-center text-sm text-slate-400">
                    {{ $review ? 'Nothing needs a look.' : 'Nothing recorded yet.' }}
                </td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <div class="p-4">{{ $logs->links() }}</div>
    </div>
</div>
@endsection
