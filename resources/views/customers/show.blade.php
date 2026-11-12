@extends('layouts.app')
@section('title', $customer->name)
@section('shows-errors', '1')

@section('content')
@php $input = 'w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500'; @endphp
<div class="max-w-4xl space-y-6" x-data="{ editing: @js($errors->any()) }">
    <a href="{{ route('customers.index') }}" class="inline-flex items-center gap-1.5 text-sm text-slate-500 hover:text-ink">
        <i data-lucide="arrow-left" class="w-4 h-4"></i> Balances
    </a>

    <div class="bg-white rounded-xl border border-slate-200 p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-lg font-semibold text-ink">{{ $customer->name }}</p>
                <p class="text-sm text-slate-500">{{ $customer->phone ?? 'No phone number' }}</p>
                <button type="button" @click="editing = !editing" class="mt-2 text-xs font-medium text-brand-600">Change name or phone</button>
            </div>
            <div class="text-right">
                <p class="text-xs font-medium text-slate-500">Still owes</p>
                <p class="text-2xl font-semibold {{ $owed > 0 ? 'text-amber-600' : 'text-emerald-600' }}">{{ $owed > 0 ? '₱' . number_format($owed, 2) : 'Paid up' }}</p>
            </div>
        </div>
        <form x-show="editing" style="display:none;" method="POST" action="{{ route('customers.update', $customer) }}" class="mt-4 pt-4 border-t border-slate-100 grid grid-cols-1 sm:grid-cols-[1fr_1fr_auto] gap-3 items-end">
            @csrf
            @method('PATCH')
            <div>
                <label for="customer-name" class="block text-xs font-medium text-slate-600 mb-1">Name</label>
                <input id="customer-name" type="text" name="name" value="{{ old('name', $customer->name) }}" required maxlength="150" class="{{ $input }}">
                @error('name')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="customer-phone" class="block text-xs font-medium text-slate-600 mb-1">Phone <span class="text-slate-400">(optional)</span></label>
                <input id="customer-phone" type="text" name="phone" value="{{ old('phone', $customer->phone) }}" maxlength="30" class="{{ $input }}">
                @error('phone')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
            </div>
            <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-lg px-5 py-2">Save</button>
        </form>
    </div>

    @if($owing->isNotEmpty())
    <div class="bg-white rounded-xl border border-slate-200">
        <div class="px-5 py-4 border-b border-slate-100">
            <p class="font-semibold text-ink">Sales with a balance</p>
            <p class="text-xs text-slate-500 mt-0.5">Press Record payment on the sale being paid. Each sale keeps its own payment history.</p>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 border-b border-slate-100">
                    <th class="px-5 py-3">Invoice</th><th class="px-2">Date</th><th class="px-2 text-right">Total</th><th class="px-2 text-right">Paid</th><th class="px-2 text-right">Balance</th><th class="pr-5"></th>
                </tr>
            </thead>
            <tbody>
                @foreach($owing as $sale)
                <tr class="border-b border-slate-50">
                    <td class="px-5 py-3 font-medium text-ink whitespace-nowrap"><a href="{{ route('pos.receipt', $sale) }}" class="hover:text-brand-600 hover:underline">{{ $sale->invoice_number }}</a></td>
                    <td class="px-2 text-slate-500 whitespace-nowrap">{{ $sale->created_at->format('M j, Y') }}</td>
                    <td class="px-2 text-right whitespace-nowrap">₱{{ number_format($sale->total_amount, 2) }}</td>
                    <td class="px-2 text-right whitespace-nowrap">₱{{ number_format($sale->payments->sum('amount'), 2) }}</td>
                    <td class="px-2 text-right whitespace-nowrap font-semibold text-amber-600">₱{{ number_format($sale->total_amount - $sale->amount_paid, 2) }}</td>
                    <td class="pr-5 py-2 text-right">
                        <a href="{{ route('pos.receipt', $sale) }}#pay" class="inline-flex items-center gap-1.5 text-xs font-medium rounded-lg px-3 py-1.5 bg-amber-50 text-amber-800 hover:bg-amber-100 whitespace-nowrap">
                            <i data-lucide="wallet" class="w-3.5 h-3.5"></i> Record payment
                        </a>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
        </div>
    </div>
    @endif

    {{-- Everything, newest first: what they bought, and each payment on it --}}
    <div class="bg-white rounded-xl border border-slate-200">
        <div class="px-5 py-4 border-b border-slate-100">
            <p class="font-semibold text-ink">History</p>
        </div>
        <ul class="divide-y divide-slate-100">
            @forelse($history as $entry)
                @php $sale = $entry['sale']; $payment = $entry['payment']; @endphp
                <li class="px-5 py-3 flex items-start justify-between gap-4">
                    <div class="min-w-0 text-sm">
                        @if($payment)
                            <p class="font-medium text-ink">Paid {{ $payment->methodLabel() }}
                                <span class="font-normal text-slate-500">on <a href="{{ route('pos.receipt', $sale) }}" class="hover:underline">{{ $sale->invoice_number }}</a></span></p>
                            <p class="text-xs text-slate-500">
                                {{ $payment->created_at->format('M j, Y h:i A') }} · received by {{ $payment->receivedBy->name ?? '-' }}
                                @if($payment->reference) · Ref. {{ $payment->reference }}@endif
                                @if($payment->note) · {{ $payment->note }}@endif
                            </p>
                        @else
                            <p class="font-medium text-ink">{{ $sale->status === 'voided' ? 'Bought (voided)' : 'Bought' }}
                                <span class="font-normal text-slate-500"><a href="{{ route('pos.receipt', $sale) }}" class="hover:underline">{{ $sale->invoice_number }}</a></span></p>
                            <p class="text-xs text-slate-500 truncate">{{ $sale->items->map(fn ($i) => $i->item_name . ($i->variant_name ? " ({$i->variant_name})" : '') . ' x' . $i->quantity)->implode(', ') }}</p>
                            <p class="text-xs text-slate-500">{{ $sale->created_at->format('M j, Y h:i A') }} · {{ $sale->user->name ?? '-' }}</p>
                        @endif
                    </div>
                    @if($payment)
                        <span class="text-sm font-semibold text-emerald-600 whitespace-nowrap">− ₱{{ number_format($payment->amount, 2) }}</span>
                    @else
                        <span class="text-sm font-semibold whitespace-nowrap {{ $sale->status === 'voided' ? 'text-slate-400 line-through' : 'text-ink' }}">+ ₱{{ number_format($sale->total_amount, 2) }}</span>
                    @endif
                </li>
            @empty
                <li class="px-5 py-8 text-center text-sm text-slate-400">No sales yet.</li>
            @endforelse
        </ul>
    </div>
</div>
@endsection
