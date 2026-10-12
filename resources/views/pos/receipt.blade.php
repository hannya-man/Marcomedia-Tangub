@extends('layouts.app')
@section('title', 'Receipt')

@section('content')
<div class="max-w-md mx-auto bg-white rounded-xl border border-slate-200 p-6">
    <div class="text-center mb-4">
        <p class="font-semibold text-ink">Marcomedia Printing &amp; Photography</p>
        <p class="text-xs text-slate-500">{{ $sale->invoice_number }}</p>
        <p class="text-xs text-slate-500">{{ $sale->created_at->format('M j, Y h:i A') }}</p>
        @if($sale->customer)
            <p class="text-xs text-slate-500 mt-1">
                Customer: {{ $sale->customer->name }}{{ $sale->customer->phone ? ' · ' . $sale->customer->phone : '' }}
            </p>
        @endif
    </div>
    <div class="border-t border-b border-dashed border-slate-300 py-3 space-y-2 text-sm">
        @foreach($sale->items as $item)
        <div class="flex justify-between">
            <div>
                <p>{{ $item->item_name }} {{ $item->variant_name ? "($item->variant_name)" : '' }} x{{ $item->quantity }}</p>
                @if($item->cutNote())
                    {{-- Cut from a board or roll: the standard piece it's charged as --}}
                    <p class="text-xs text-slate-500">{{ $item->cutNote() }}</p>
                @endif
                @if($item->customization_details)
                    <p class="text-xs text-slate-500">{{ $item->customization_details }}</p>
                @endif
                @if($item->isOnOrder())
                    {{-- Sold while out of stock --}}
                    <p class="text-xs font-medium text-amber-600">On order: out of stock, made as soon as the stock comes in</p>
                @elseif($item->backordered_at)
                    <p class="text-xs text-emerald-600">Ordered while out of stock. Stock in {{ $item->backorder_filled_at->format('M j') }}</p>
                @endif
            </div>
            <p>₱{{ number_format($item->subtotal, 2) }}</p>
        </div>
        @endforeach
    </div>
    @if($sale->items->contains(fn ($i) => $i->isOnOrder()))
        <p class="mt-3 text-center text-xs text-slate-500">
            Marcomedia accepts orders even when items are out of stock. We'll make your order as soon as the stock arrives.
        </p>
    @endif
    <div class="pt-3 space-y-1 text-sm">
        <div class="flex justify-between"><span>Subtotal</span><span>₱{{ number_format($sale->subtotal,2) }}</span></div>
        <div class="flex justify-between"><span>Discount</span><span>-₱{{ number_format($sale->discount_amount,2) }}</span></div>
        <div class="flex justify-between font-semibold text-ink"><span>Total</span><span>₱{{ number_format($sale->total_amount,2) }}</span></div>
        <div class="flex justify-between"><span>Paid ({{ $sale->payment_method === 'gcash' ? 'GCash' : ucfirst($sale->payment_method) }})</span><span>₱{{ number_format($sale->amount_paid,2) }}</span></div>
        @if($sale->amount_paid < $sale->total_amount)
            {{-- A down payment: the slip says what is still owed --}}
            <div class="flex justify-between font-semibold text-amber-600"><span>Balance due</span><span>₱{{ number_format($sale->total_amount - $sale->amount_paid, 2) }}</span></div>
        @else
            <div class="flex justify-between"><span>Change</span><span>₱{{ number_format($sale->change_amount,2) }}</span></div>
        @endif
    </div>
    <div class="text-center mt-6">
        <a href="{{ route('pos.index') }}" class="text-brand-600 text-sm">New Sale</a>
    </div>
</div>
@endsection
