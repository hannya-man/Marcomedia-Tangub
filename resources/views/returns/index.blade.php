@extends('layouts.app')
@section('title', 'Returns')
@section('shows-errors', '1')

@section('content')
@php
    $input = 'w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500';
    // What can still come back: handed over (not on order) and not all returned already.
    $returnable = $sale && in_array($sale->status, ['pending', 'processing', 'completed'], true)
        ? $sale->items->reject->isOnOrder()->filter(fn ($i) => $i->quantity - (int) ($returned[$i->id] ?? 0) > 0)
        : collect();
@endphp
<div class="max-w-4xl space-y-6">
    <p class="text-sm text-slate-500">
        Accept an item a customer brings back. Type the invoice number from their receipt, pick the item, and say why it came back:
        that's the proof for the return. A good item goes back on sale. A damaged one is kept apart and never sold.
    </p>

    <form method="GET" class="bg-white rounded-xl border border-slate-200 p-4 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[14rem]">
            <label for="invoice" class="block text-xs font-medium text-slate-600 mb-1">Invoice number</label>
            <input id="invoice" type="text" name="invoice" value="{{ request('invoice') }}" placeholder="INV-20261112-0001" required autocomplete="off" class="{{ $input }}">
        </div>
        <button type="submit" class="inline-flex items-center gap-1.5 bg-ink hover:bg-slate-800 text-white text-sm font-medium rounded-lg px-4 py-2">
            <i data-lucide="search" class="w-4 h-4"></i> Find the sale
        </button>
    </form>

    @if(request()->filled('invoice') && ! $sale)
        <p class="text-sm text-red-700 bg-red-50 border border-red-200 rounded-lg px-4 py-3">No sale has the invoice number {{ request('invoice') }}. Check it on the customer's receipt.</p>
    @endif

    @if($sale)
    <div class="bg-white rounded-xl border border-slate-200">
        <div class="px-5 py-4 border-b border-slate-100 flex flex-wrap justify-between gap-2">
            <div>
                <p class="font-semibold text-ink"><a href="{{ route('pos.receipt', $sale) }}" class="hover:underline">{{ $sale->invoice_number }}</a></p>
                <p class="text-xs text-slate-500">{{ $sale->created_at->format('M j, Y h:i A') }} · {{ $sale->customer->name ?? 'Walk-in' }} · {{ ucfirst($sale->status) }}</p>
            </div>
            <p class="text-sm font-medium text-ink">₱{{ number_format($sale->total_amount, 2) }}</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 border-b border-slate-100">
                    <th class="px-5 py-3">Item</th><th class="px-2 text-right">Sold</th><th class="px-2 text-right">Returned</th><th class="px-5 text-right">Can come back</th>
                </tr>
            </thead>
            <tbody>
                @foreach($sale->items as $item)
                @php $back = (int) ($returned[$item->id] ?? 0); @endphp
                <tr class="border-b border-slate-50">
                    <td class="px-5 py-3">{{ $item->item_name }}@if($item->variant_name) <span class="text-slate-400">({{ $item->variant_name }})</span>@endif
                        @if($item->isOnOrder())<span class="block text-xs text-amber-600">Still on order: never handed over</span>@endif
                    </td>
                    <td class="px-2 text-right">{{ $item->quantity }}</td>
                    <td class="px-2 text-right">{{ $back ?: '-' }}</td>
                    <td class="px-5 text-right font-medium">{{ $item->isOnOrder() ? 0 : $item->quantity - $back }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>

        @if($returnable->isEmpty())
            <p class="px-5 py-4 text-sm text-slate-500">
                {{ in_array($sale->status, ['voided', 'archived'], true) ? 'This sale is ' . $sale->status . ': nothing on it can be returned.' : 'Nothing on this sale can come back now.' }}
            </p>
        @else
        <form method="POST" action="{{ route('returns.store') }}" class="p-5 border-t border-slate-100 grid grid-cols-1 sm:grid-cols-2 gap-4"
              x-data="{ condition: @js(old('condition', 'good')) }">
            @csrf
            <div class="sm:col-span-2">
                <label for="return-item" class="block text-xs font-medium text-slate-600 mb-1">Item coming back</label>
                <select id="return-item" name="sale_item_id" required class="{{ $input }}">
                    @foreach($returnable as $item)
                        <option value="{{ $item->id }}" @selected(old('sale_item_id') == $item->id)>
                            {{ $item->item_name }}{{ $item->variant_name ? " ({$item->variant_name})" : '' }}: up to {{ $item->quantity - (int) ($returned[$item->id] ?? 0) }}
                        </option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="return-quantity" class="block text-xs font-medium text-slate-600 mb-1">How many</label>
                <input id="return-quantity" type="number" name="quantity" min="1" value="{{ old('quantity', 1) }}" required class="{{ $input }}">
            </div>
            <div>
                <span class="block text-xs font-medium text-slate-600 mb-1">Condition</span>
                <input type="hidden" name="condition" :value="condition">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" @click="condition = 'good'" :aria-pressed="condition === 'good'" title="Can be sold again: it goes back in stock"
                        class="text-sm rounded-lg border px-3 py-2" :class="condition === 'good' ? 'border-brand-500 bg-brand-50 text-brand-700 font-medium' : 'border-slate-300 bg-white text-slate-600'">Good</button>
                    <button type="button" @click="condition = 'damaged'" :aria-pressed="condition === 'damaged'" title="Can't be sold again: kept apart as damaged"
                        class="text-sm rounded-lg border px-3 py-2" :class="condition === 'damaged' ? 'border-red-400 bg-red-50 text-red-700 font-medium' : 'border-slate-300 bg-white text-slate-600'">Damaged</button>
                </div>
            </div>
            <div class="sm:col-span-2">
                <label for="return-reason" class="block text-xs font-medium text-slate-600 mb-1">Why did it come back?</label>
                <input id="return-reason" type="text" name="reason" value="{{ old('reason') }}" required minlength="3" maxlength="255"
                       placeholder="e.g. wrong size printed, customer changed their mind" class="{{ $input }}">
            </div>
            @if($errors->any())
                <div class="sm:col-span-2 text-xs text-red-600">@foreach(collect($errors->all())->unique() as $message)<p>{{ $message }}</p>@endforeach</div>
            @endif
            <div class="sm:col-span-2 flex justify-end">
                <button type="submit" class="inline-flex items-center gap-1.5 bg-ink hover:bg-slate-800 text-white text-sm font-medium rounded-lg px-5 py-2">
                    <i data-lucide="undo-2" class="w-4 h-4"></i> Accept the return
                </button>
            </div>
        </form>
        @endif
    </div>
    @endif

    <div class="bg-white rounded-xl border border-slate-200">
        <div class="px-5 py-4 border-b border-slate-100"><p class="font-semibold text-ink">Recent returns</p></div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 border-b border-slate-100">
                    <th class="px-5 py-3">Date</th><th class="px-2">Invoice</th><th class="px-2">Item</th><th class="px-2 text-right">Qty</th><th class="px-2">Condition</th><th class="px-2">Why</th><th class="px-5">Accepted by</th>
                </tr>
            </thead>
            <tbody>
                @forelse($recent as $return)
                <tr class="border-b border-slate-50">
                    <td class="px-5 py-3 whitespace-nowrap text-slate-500">{{ $return->created_at?->format('M j, Y h:i A') }}</td>
                    <td class="px-2 whitespace-nowrap">
                        @if($return->saleItem?->sale)<a href="{{ route('pos.receipt', $return->saleItem->sale) }}" class="hover:underline">{{ $return->saleItem->sale->invoice_number }}</a>@else - @endif
                    </td>
                    <td class="px-2">{{ $return->saleItem->item_name ?? '-' }}@if($return->saleItem?->variant_name) <span class="text-slate-400">({{ $return->saleItem->variant_name }})</span>@endif</td>
                    <td class="px-2 text-right">{{ $return->quantity }}</td>
                    <td class="px-2"><span class="px-2 py-0.5 rounded-full text-xs font-medium {{ $return->condition === 'good' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700' }}">{{ ucfirst($return->condition) }}</span></td>
                    <td class="px-2 text-slate-600">{{ $return->reason ?? '-' }}</td>
                    <td class="px-5 text-slate-600">{{ $return->processedBy->name ?? '-' }}</td>
                </tr>
                @empty
                <tr><td colspan="7" class="p-8 text-center text-sm text-slate-400">No returns yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>
</div>
@endsection
