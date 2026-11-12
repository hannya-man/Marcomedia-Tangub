@extends('layouts.app')
@section('title', ($shift->user->name ?? 'Shift') . "'s shift")

@section('content')
@php
    $diff = $shift->status === 'closed' ? (float) $shift->variance : null;
    $cashTaken = $payments->where('method', 'cash')->sum('amount');
    $stat = 'bg-white rounded-xl border border-slate-200 p-5';
@endphp
<div class="max-w-5xl space-y-6">
    <a href="{{ route('shifts.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-ink">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> Shifts
    </a>

    <div>
        <p class="text-lg font-semibold text-ink">{{ $shift->user->name ?? '-' }}, {{ $shift->opened_at->format('l, M j, Y') }}</p>
        <p class="text-sm text-slate-500">
            {{ $shift->opened_at->format('h:i A') }} to {{ $shift->closed_at ? $shift->closed_at->format('M j, h:i A') : 'now (still on shift)' }}
        </p>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="{{ $stat }}">
            <p class="text-xs font-medium text-slate-500">Sales</p>
            <p class="text-xl font-semibold text-ink mt-1">₱{{ number_format($sales->sum('total_amount'), 2) }}</p>
            <p class="text-xs text-slate-400">{{ $sales->count() }} {{ $sales->count() === 1 ? 'sale' : 'sales' }}</p>
        </div>
        <div class="{{ $stat }}">
            <p class="text-xs font-medium text-slate-500">Payments taken</p>
            <p class="text-xl font-semibold text-ink mt-1">₱{{ number_format($payments->sum('amount'), 2) }}</p>
            <p class="text-xs text-slate-400">₱{{ number_format($cashTaken, 2) }} cash, ₱{{ number_format($payments->sum('amount') - $cashTaken, 2) }} GCash</p>
        </div>
        <div class="{{ $stat }}">
            <p class="text-xs font-medium text-slate-500">The drawer should have</p>
            <p class="text-xl font-semibold text-ink mt-1">₱{{ number_format($expected, 2) }}</p>
            <p class="text-xs text-slate-400">₱{{ number_format($shift->opening_cash, 2) }} at the start + cash taken</p>
        </div>
        <div class="{{ $stat }}">
            <p class="text-xs font-medium text-slate-500">Cash difference</p>
            @if($diff === null)
                <p class="text-xl font-semibold text-slate-400 mt-1">Not yet</p>
                <p class="text-xs text-slate-400">counted when the shift ends</p>
            @else
                <p class="text-xl font-semibold mt-1 {{ abs($diff) >= 0.01 ? 'text-red-600' : 'text-emerald-600' }}">{{ abs($diff) < 0.01 ? 'None' : '₱' . number_format(abs($diff), 2) . ($diff < 0 ? ' short' : ' over') }}</p>
                <p class="text-xs text-slate-400">counted ₱{{ number_format($shift->actual_cash, 2) }}</p>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-6">
        {{-- Everything done in the shift, in order --}}
        <div class="lg:col-span-3 bg-white rounded-xl border border-slate-200">
            <div class="px-5 py-4 border-b border-slate-100"><p class="font-semibold text-ink">Timeline</p></div>
            <ol class="divide-y divide-slate-100">
                @forelse($timeline as $entry)
                <li class="px-5 py-3 flex gap-4 text-sm {{ $entry->needs_review ? 'bg-amber-50/60' : '' }}">
                    <time class="w-20 flex-shrink-0 text-xs text-slate-400 pt-0.5">{{ $entry->created_at?->format('h:i A') }}</time>
                    <div class="min-w-0">
                        <p class="text-slate-700">@if($entry->needs_review)<i data-lucide="alert-triangle" class="inline w-3.5 h-3.5 text-amber-600 -mt-0.5 mr-1"></i>@endif{{ $entry->description }}</p>
                        @if($entry->user_id !== $shift->user_id)<p class="text-xs text-slate-400">by {{ $entry->user->name ?? '-' }}</p>@endif
                    </div>
                </li>
                @empty
                <li class="px-5 py-8 text-center text-sm text-slate-400">Nothing recorded in this shift yet.</li>
                @endforelse
            </ol>
        </div>

        {{-- The payments that make up the drawer --}}
        <div class="lg:col-span-2 bg-white rounded-xl border border-slate-200 self-start">
            <div class="px-5 py-4 border-b border-slate-100"><p class="font-semibold text-ink">Payments taken</p></div>
            <ul class="divide-y divide-slate-100">
                @forelse($payments as $payment)
                <li class="px-5 py-3 flex justify-between gap-3 text-sm">
                    <div class="min-w-0">
                        <p class="text-ink"><a href="{{ route('pos.receipt', $payment->sale) }}" class="hover:underline">{{ $payment->sale->invoice_number }}</a>
                            <span class="text-slate-500">· {{ $payment->methodLabel() }}</span></p>
                        <p class="text-xs text-slate-400">{{ $payment->created_at->format('h:i A') }} · {{ $payment->sale->customer->name ?? 'Walk-in' }}</p>
                    </div>
                    <span class="whitespace-nowrap">₱{{ number_format($payment->amount, 2) }}</span>
                </li>
                @empty
                <li class="px-5 py-8 text-center text-sm text-slate-400">No payments taken.</li>
                @endforelse
            </ul>
        </div>
    </div>
</div>
@endsection
