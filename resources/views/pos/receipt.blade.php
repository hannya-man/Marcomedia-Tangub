@extends('layouts.app')
@section('title', 'Sale ' . $sale->invoice_number)

@section('content')
@php
    $balance = max((float) $sale->total_amount - (float) $sale->amount_paid, 0);
    $canPay = $balance > 0 && in_array($sale->status, ['pending', 'processing'], true);
    $statusText = [
        'pending' => 'Pending: down payment, balance still owed',
        'processing' => 'Processing: paid, being made',
        'completed' => 'Completed: released to the customer',
        'voided' => 'Voided',
        'archived' => 'Archived',
    ][$sale->status] ?? ucfirst($sale->status);
@endphp
<div class="max-w-md mx-auto space-y-4">
<div class="bg-white rounded-xl border border-slate-200 p-6">
    <div class="text-center mb-4">
        <p class="font-semibold text-ink">Marcomedia Printing &amp; Photography</p>
        <p class="text-xs text-slate-500">{{ $sale->invoice_number }}</p>
        <p class="text-xs text-slate-500">{{ $sale->created_at->format('M j, Y h:i A') }} · {{ $sale->user->name ?? '' }}</p>
        @if($sale->customer)
            <p class="text-xs text-slate-500 mt-1">
                Customer: {{ $sale->customer->name }}{{ $sale->customer->phone ? ' · ' . $sale->customer->phone : '' }}
            </p>
        @endif
        <p class="text-xs font-medium mt-1 {{ $sale->status === 'voided' ? 'text-red-600' : 'text-slate-600' }}">{{ $statusText }}</p>
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
    </div>

    {{-- Every payment on this sale, oldest first: the one at the counter, then each one after it --}}
    <div class="mt-4 pt-3 border-t border-dashed border-slate-300">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 mb-1">Payments</p>
        @forelse($sale->payments as $payment)
            <div class="flex justify-between gap-3 text-sm py-1.5">
                <div class="min-w-0">
                    <p>{{ $payment->created_at->format('M j, Y h:i A') }} · {{ $payment->methodLabel() }}@if($payment->reference) <span class="text-slate-500 whitespace-nowrap">· Ref. {{ $payment->reference }}</span>@endif</p>
                    <p class="text-xs text-slate-500">Received by {{ $payment->receivedBy->name ?? '-' }}@if($payment->note) · {{ $payment->note }}@endif</p>
                </div>
                <p class="whitespace-nowrap">₱{{ number_format($payment->amount, 2) }}</p>
            </div>
        @empty
            <p class="text-sm text-slate-500 py-1.5">No payments yet.</p>
        @endforelse
        <div class="flex justify-between text-sm font-medium text-ink pt-2 mt-1 border-t border-slate-100">
            <span>Paid so far</span><span>₱{{ number_format($sale->payments->sum('amount'), 2) }}</span>
        </div>
        @if($balance > 0)
            {{-- A down payment: the slip says what is still owed --}}
            <div class="flex justify-between font-semibold text-amber-600 text-sm"><span>Balance due</span><span>₱{{ number_format($balance, 2) }}</span></div>
        @elseif($sale->change_amount > 0)
            <div class="flex justify-between text-sm"><span>Change given</span><span>₱{{ number_format($sale->change_amount, 2) }}</span></div>
        @endif
    </div>
</div>

@if($canPay)
{{-- The next payment on the balance. Cash can be more than what's owed (the rest is change); GCash can't. --}}
<form id="pay" method="POST" action="{{ route('sales.payments.store', $sale) }}" class="bg-white rounded-xl border border-slate-200 p-6 space-y-3 scroll-mt-4"
      x-data="{ method: @js(old('method', 'cash')), amount: @js(old('amount', '')), owed: @js($balance),
                peso(n) { return '₱' + (Number(n) || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) },
                get received() { return Math.round((Number(this.amount) || 0) * 100) / 100 },
                get tooMuch() { return this.method !== 'cash' && this.received > this.owed } }">
    @csrf
    <p class="font-semibold text-ink flex items-center gap-2"><i data-lucide="wallet" class="w-4 h-4 text-brand-600"></i> Record a payment</p>
    <p class="text-sm text-slate-500">
        {{ $sale->customer->name ?? 'The customer' }} still owes <span class="font-medium text-ink">₱{{ number_format($balance, 2) }}</span>.
        Type what they handed over, then press Save payment.
    </p>
    <div>
        <input type="hidden" name="method" :value="method">
        <div class="grid grid-cols-2 gap-2">
            @foreach(\App\Models\SalePayment::METHODS as $value => $text)
                <button type="button" @click="method = @js($value)" :aria-pressed="method === @js($value)"
                    class="text-sm rounded-lg border px-3 py-2"
                    :class="method === @js($value) ? 'border-brand-500 bg-brand-50 text-brand-700 font-medium' : 'border-slate-300 bg-white text-slate-600'">
                    {{ $text }}
                </button>
            @endforeach
        </div>
    </div>
    <div>
        <label for="pay-amount" class="block text-xs font-medium text-slate-600 mb-1">Amount received</label>
        <input id="pay-amount" type="number" name="amount" x-model="amount" step="0.01" min="0.01" required placeholder="0.00"
               class="w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
    </div>
    <div x-show="method === 'gcash'" style="display:none;">
        <label for="pay-reference" class="block text-xs font-medium text-slate-600 mb-1">GCash reference no. <span class="text-slate-400">(optional)</span></label>
        <input id="pay-reference" type="text" name="reference" value="{{ old('reference') }}" maxlength="100" :disabled="method !== 'gcash'"
               class="w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
    </div>
    <p class="text-sm" x-show="received > 0" style="display:none;"
       :class="tooMuch ? 'text-red-600' : (received < owed ? 'text-amber-600' : 'text-emerald-600')"
       x-text="tooMuch ? `A GCash payment can't be more than the ${peso(owed)} owed.`
             : received < owed ? `Still owes ${peso(owed - received)} after this.`
             : received > owed ? `Paid in full. Change: ${peso(received - owed)}`
             : 'Paid in full.'"></p>
    <button type="submit" :disabled="!(received > 0) || tooMuch"
        class="w-full bg-ink hover:bg-slate-800 disabled:opacity-40 disabled:cursor-not-allowed text-white rounded-lg py-2.5 text-sm font-medium flex items-center justify-center gap-2">
        <i data-lucide="check-circle" class="w-4 h-4"></i> Save payment
    </button>
</form>
@endif

<div class="flex flex-wrap justify-center gap-x-5 gap-y-2 text-sm">
    <a href="{{ route('sales.index') }}" class="text-slate-500">Back to Sales</a>
    @if($sale->customer)
        <a href="{{ route('customers.show', $sale->customer) }}" class="text-slate-500">{{ $sale->customer->name }}'s balance</a>
    @endif
    <a href="{{ route('pos.index') }}" class="text-brand-600">New Sale</a>
</div>
</div>
@endsection
