@extends('layouts.app')
@section('title', 'Balances (utang)')

@section('content')
@php $applyFilter = "\$el.closest('form').requestSubmit()"; @endphp
<div class="space-y-6">
    <p class="text-sm text-slate-500">
        Customers who paid a down payment and still owe the rest. Open a customer to see everything they bought and every payment,
        and to record a payment on one of their sales.
    </p>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <p class="text-xs font-medium text-slate-500">Owed to you</p>
            <p class="text-2xl font-semibold text-amber-600 mt-1">₱{{ number_format($totalOwed, 2) }}</p>
        </div>
        <div class="bg-white rounded-xl border border-slate-200 p-5">
            <p class="text-xs font-medium text-slate-500">Customers who owe</p>
            <p class="text-2xl font-semibold text-ink mt-1">{{ $owingCount }}</p>
        </div>
    </div>
    @if($unnamedOwed > 0)
        <p class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-lg px-4 py-3">
            ₱{{ number_format($unnamedOwed, 2) }} of it is on older down payments that have no customer name.
            Find them in <a href="{{ route('sales.index', ['status' => 'pending']) }}" class="font-medium underline">Sales, status Pending</a>.
        </p>
    @endif

    <div class="bg-white rounded-xl border border-slate-200">
        <form method="GET" class="flex flex-wrap items-center gap-4 p-4 border-b border-slate-100">
            <div class="relative">
                <i data-lucide="search" class="w-3.5 h-3.5 text-slate-400 absolute left-3 top-1/2 -translate-y-1/2"></i>
                <input type="text" name="search" value="{{ request('search') }}" placeholder="Name or phone..." autocomplete="off" aria-label="Search customers"
                    @input.debounce.400ms="$el.form.requestSubmit()"
                    x-data x-init="if ($el.value) { $el.focus(); $el.setSelectionRange($el.value.length, $el.value.length) }"
                    class="text-sm rounded-md border border-slate-300 bg-white pl-9 pr-3 py-2 w-60 shadow-sm focus:ring-2 focus:ring-brand-500 focus:border-brand-500">
            </div>
            <label class="flex items-center gap-2 text-sm text-slate-600 select-none cursor-pointer">
                <input type="checkbox" name="all" value="1" @checked(request()->boolean('all')) onchange="{{ $applyFilter }}" x-data
                       class="w-4 h-4 rounded border-slate-300 accent-brand-600">
                Show customers who are paid up too
            </label>
        </form>

        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-slate-500 border-b border-slate-100">
                    <th class="p-4">Customer</th><th class="px-2">Phone</th><th class="px-2">Last bought</th><th class="px-2 text-right">Owes</th><th class="pr-4"></th>
                </tr>
            </thead>
            <tbody>
                @forelse($customers as $customer)
                <tr class="border-b border-slate-50">
                    <td class="p-4 font-medium text-ink">
                        <a href="{{ route('customers.show', $customer) }}" class="hover:text-brand-600 hover:underline">{{ $customer->name }}</a>
                    </td>
                    <td class="px-2 text-slate-600">{{ $customer->phone ?? '-' }}</td>
                    <td class="px-2 text-slate-500 whitespace-nowrap">{{ $customer->last_sale_at ? \Illuminate\Support\Carbon::parse($customer->last_sale_at)->format('M j, Y') : '-' }}</td>
                    <td class="px-2 text-right whitespace-nowrap">
                        @if($customer->owed > 0)
                            <span class="font-semibold text-amber-600">₱{{ number_format($customer->owed, 2) }}</span>
                            <span class="block text-xs text-slate-400">on {{ $customer->sales_owing }} {{ $customer->sales_owing == 1 ? 'sale' : 'sales' }}</span>
                        @else
                            <span class="font-medium text-emerald-600">Paid up</span>
                        @endif
                    </td>
                    <td class="pr-4 text-right">
                        <a href="{{ route('customers.show', $customer) }}" class="inline-flex items-center gap-1.5 text-xs font-medium rounded-lg px-3 py-1.5 bg-brand-50 text-brand-700 hover:bg-brand-100"
                           title="What they bought, every payment, and recording the next one">
                            <i data-lucide="wallet" class="w-3.5 h-3.5"></i> {{ $customer->owed > 0 ? 'Open to pay' : 'Open' }}
                        </a>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="p-8 text-center text-sm text-slate-400">
                    @if(request()->filled('search'))
                        No customers match.
                    @elseif(request()->boolean('all'))
                        No customers saved yet. They're added at Billing/POS.
                    @else
                        Nobody owes you anything right now.
                    @endif
                </td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
        <div class="p-4">{{ $customers->links() }}</div>
    </div>
</div>
@endsection
