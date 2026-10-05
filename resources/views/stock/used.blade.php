@extends('layouts.app')
@section('title', 'Used Materials')

@section('content')
@inject('packs', 'App\Services\PackService')
@php
    $field = 'mt-1 text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2';
    $th = 'text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700';
    $reasons = ['received' => 'Received', 'used' => 'Used', 'broken' => 'Broken or damaged', 'miscount' => 'Miscount', 'other' => 'Other'];
@endphp

@include('stock._tabs')

<div class="flex flex-wrap items-end justify-between gap-4 mb-6">
    <p class="text-sm text-slate-500 dark:text-slate-400 max-w-xl">
        Everything opened, used, rejected or removed. Errors are rejected automatically: they count as used,
        like a sale, but bring in no money, so they never add to profit.
    </p>
    <form method="GET" action="{{ route('stock.used') }}" class="flex flex-wrap items-end gap-2">
        <div>
            <label for="used-month" class="text-xs font-medium text-slate-600 dark:text-slate-300">Month</label>
            <input id="used-month" type="month" name="month" value="{{ $start->format('Y-m') }}" class="{{ $field }} block">
        </div>
        <div>
            <label for="used-type" class="text-xs font-medium text-slate-600 dark:text-slate-300">Show</label>
            <select id="used-type" name="type" class="{{ $field }} block">
                @foreach($types as $value => $text)
                    <option value="{{ $value }}" @selected($type === $value)>{{ $text }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="used-material" class="text-xs font-medium text-slate-600 dark:text-slate-300">Material</label>
            <select id="used-material" name="material" class="{{ $field }} block">
                <option value="0">All</option>
                @foreach($materialOptions as $o)
                    <option value="{{ $o->id }}" @selected($materialId === $o->id)>{{ $o->name }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="text-sm bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-4 py-2">Show</button>
    </form>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
        <p class="text-xs text-slate-500 dark:text-slate-400">Sales that used material</p>
        <p class="text-2xl font-semibold text-ink dark:text-white mt-1">{{ $totals['sales'] }}</p>
    </div>
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
        <p class="text-xs text-slate-500 dark:text-slate-400">Raw units used up</p>
        <p class="text-2xl font-semibold text-ink dark:text-white mt-1">{{ $packs->fmt($totals['used_up']) }}</p>
    </div>
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
        <p class="text-xs text-slate-500 dark:text-slate-400">Errors, rejected</p>
        <p class="text-2xl font-semibold mt-1 {{ $totals['errors'] > 0 ? 'text-red-600' : 'text-ink dark:text-white' }}">{{ $totals['errors'] }}</p>
    </div>
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-4">
        <p class="text-xs text-slate-500 dark:text-slate-400">Lost to errors</p>
        <p class="text-2xl font-semibold mt-1 {{ $totals['lost'] > 0 ? 'text-red-600' : 'text-ink dark:text-white' }}">₱{{ number_format($totals['lost'], 2) }}</p>
        <p class="text-xs text-slate-400">Not counted as profit</p>
    </div>
</div>

<div class="grid grid-cols-1 gap-4 mb-6">
    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="p-4 border-b border-slate-100 dark:border-slate-700">
            <p class="font-medium text-ink dark:text-white">Raw Materials, {{ $start->format('F Y') }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">Whole units: counted when removed by hand.</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="{{ $th }}">
                <th class="p-3">Material</th><th class="p-3 text-right">Used up</th><th class="p-3 text-right">Sales</th><th class="p-3 text-right">Jobs</th><th class="p-3 text-right">Errors</th>
            </tr></thead>
            <tbody>
            @forelse($rawRows as $row)
                <tr class="border-b border-slate-50 dark:border-slate-700">
                    <td class="p-3 text-ink dark:text-white">{{ $row['material']->name }}</td>
                    <td class="p-3 text-right">{{ $packs->fmt($row['used_up']) }} {{ $row['material']->unit }}</td>
                    <td class="p-3 text-right">{{ $row['sales'] }}</td>
                    <td class="p-3 text-right">{{ $row['jobs'] }}</td>
                    <td class="p-3 text-right {{ $row['errors'] > 0 ? 'text-red-600 font-medium' : '' }}">{{ $row['errors'] }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-6 text-center text-sm text-slate-400">Nothing this month.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="p-4 border-b border-slate-100 dark:border-slate-700">
            <p class="font-medium text-ink dark:text-white">Materials, {{ $start->format('F Y') }}</p>
            <p class="text-xs text-slate-500 dark:text-slate-400">Pieces: counted as they are used.</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="{{ $th }}">
                <th class="p-3">Material</th><th class="p-3 text-right">Sales</th><th class="p-3 text-right">Jobs</th><th class="p-3 text-right">Errors</th><th class="p-3 text-right">Broken</th><th class="p-3 text-right">Lost to errors</th>
            </tr></thead>
            <tbody>
            @forelse($pieceRows as $row)
                <tr class="border-b border-slate-50 dark:border-slate-700">
                    <td class="p-3 text-ink dark:text-white">{{ $row['material']->name }}</td>
                    <td class="p-3 text-right">{{ $packs->fmt($row['sales']) }}</td>
                    <td class="p-3 text-right">{{ $packs->fmt($row['jobs']) }}</td>
                    <td class="p-3 text-right {{ $row['errors'] > 0 ? 'text-red-600 font-medium' : '' }}">{{ $packs->fmt($row['errors']) }}</td>
                    <td class="p-3 text-right">{{ $packs->fmt($row['broken']) }}</td>
                    <td class="p-3 text-right whitespace-nowrap">{{ $row['lost'] === null ? '-' : '₱' . number_format($row['lost'], 2) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="p-6 text-center text-sm text-slate-400">Nothing this month.</td></tr>
            @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <div class="p-4 border-b border-slate-100 dark:border-slate-700">
        <p class="font-medium text-ink dark:text-white">Log</p>
        <p class="text-xs text-slate-500 dark:text-slate-400">Newest first, up to 200 lines. This replaces the logbook.</p>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead><tr class="{{ $th }}">
            <th class="px-4 py-3">When</th><th class="px-3 py-3">Material</th><th class="px-3 py-3">Pack or unit</th><th class="px-3 py-3">What</th><th class="px-3 py-3 text-right">Amount</th><th class="px-3 py-3 text-right">Left</th><th class="px-3 py-3">Details</th><th class="px-3 py-3 pr-4">By</th>
        </tr></thead>
        <tbody>
        @forelse($log as $u)
            @php
                $badge = match($u->type) {
                    'open' => 'bg-emerald-100 text-emerald-700',
                    'sale', 'job' => 'bg-blue-100 text-blue-700',
                    'error' => 'bg-red-100 text-red-700',
                    'adjustment' => 'bg-amber-100 text-amber-700',
                    default => 'bg-slate-100 text-slate-600',
                };
                $details = array_filter([
                    ($u->type === 'adjustment' && $u->reason && ! in_array($u->reason, ['used', 'received'], true)) ? ($reasons[$u->reason] ?? $u->reason) : null,
                    $u->note,
                ]);
            @endphp
            <tr class="border-b border-slate-50 dark:border-slate-700 {{ $u->type === 'error' ? 'bg-red-50 dark:bg-red-900/30' : '' }}">
                <td class="px-4 py-3 text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ $u->created_at?->format('M j, h:i A') }}</td>
                <td class="p-3 text-ink dark:text-white">{{ $u->material->name ?? '-' }}</td>
                <td class="p-3 font-medium text-ink dark:text-white whitespace-nowrap">{{ $u->pack->code ?? 'Stock' }}</td>
                <td class="p-3"><span class="px-2 py-0.5 rounded-full text-xs whitespace-nowrap {{ $badge }}">{{ $u->type_label }}</span></td>
                <td class="p-3 text-right font-medium whitespace-nowrap {{ $u->quantity > 0 ? 'text-emerald-600' : ($u->quantity < 0 ? 'text-red-600' : 'text-slate-400') }}">
                    @if($u->quantity == 0)
                        -
                    @else
                        {{ $u->quantity > 0 ? '+' : '' }}{{ $packs->fmt($u->quantity) }} {{ $u->material->unit ?? '' }}
                    @endif
                </td>
                <td class="p-3 text-right text-slate-500 dark:text-slate-400">{{ $packs->fmt($u->qty_after) }}</td>
                <td class="p-3 text-slate-500 dark:text-slate-400">
                    @if($u->sale)<span class="whitespace-nowrap">{{ $u->sale->invoice_number }}</span>{{ $details ? ', ' : '' }}@endif{{ $details ? implode(', ', $details) : ($u->sale ? '' : '-') }}
                </td>
                <td class="p-3 pr-4 text-slate-500 dark:text-slate-400 whitespace-nowrap">{{ $u->user->name ?? '-' }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="p-8 text-center text-sm text-slate-400">Nothing for {{ $start->format('F Y') }} with these filters.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
