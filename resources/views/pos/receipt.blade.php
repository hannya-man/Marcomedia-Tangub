@extends('layouts.app')
@section('title', 'Receipt')

@section('content')
<div class="max-w-md mx-auto bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-6">
    <div class="text-center mb-4">
        <p class="font-semibold text-ink dark:text-white">Marcomedia Printing &amp; Photography</p>
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $sale->invoice_number }}</p>
        <p class="text-xs text-slate-500 dark:text-slate-400">{{ $sale->created_at->format('M j, Y h:i A') }}</p>
    </div>
    <div class="border-t border-b border-dashed border-slate-300 py-3 space-y-2 text-sm">
        @foreach($sale->items as $item)
        <div class="flex justify-between">
            <div>
                <p>{{ $item->item_name }} {{ $item->variant_name ? "($item->variant_name)" : '' }} x{{ $item->quantity }}</p>
                @if($item->customization_details)
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ $item->customization_details }}</p>
                @endif
            </div>
            <p>₱{{ number_format($item->subtotal, 2) }}</p>
        </div>
        @endforeach
    </div>
    <div class="pt-3 space-y-1 text-sm">
        <div class="flex justify-between"><span>Subtotal</span><span>₱{{ number_format($sale->subtotal,2) }}</span></div>
        <div class="flex justify-between"><span>Discount</span><span>-₱{{ number_format($sale->discount_amount,2) }}</span></div>
        <div class="flex justify-between font-semibold text-ink dark:text-white"><span>Total</span><span>₱{{ number_format($sale->total_amount,2) }}</span></div>
        <div class="flex justify-between"><span>Paid ({{ ucfirst($sale->payment_method) }})</span><span>₱{{ number_format($sale->amount_paid,2) }}</span></div>
        <div class="flex justify-between"><span>Change</span><span>₱{{ number_format($sale->change_amount,2) }}</span></div>
    </div>
    <div class="text-center mt-6">
        <a href="{{ route('pos.index') }}" class="text-brand-600 text-sm">New Sale</a>
    </div>
</div>
@endsection
