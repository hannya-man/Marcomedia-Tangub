@extends('layouts.app')
@section('title', 'Sales & Orders Archive')

@section('content')
<a href="{{ route('sales.index') }}" class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg px-4 py-2 mb-4 shadow-sm">
    <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Sales &amp; Orders
</a>

<div class="space-y-6">
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex items-center gap-2">
            <i data-lucide="banknote" class="w-4 h-4 text-slate-500"></i>
            <p class="font-medium text-ink">Archived Sales</p>
            <span class="text-xs text-slate-400">— records older than 2 years are moved here automatically</span>
        </div>
        <div class="overflow-x-auto">
<table class="w-full text-sm">
            <thead><tr class="text-left text-slate-500 bg-slate-50 border-b border-slate-200">
                <th class="p-4">Invoice</th><th>Customer</th><th class="text-right">Total</th><th>Date</th><th></th>
            </tr></thead>
            <tbody>
                @forelse($archivedSales as $sale)
                <tr class="border-b border-slate-50">
                    <td class="p-4 font-medium text-ink">{{ $sale->invoice_number }}</td>
                    <td>{{ $sale->customer->name ?? 'Walk-in' }}</td>
                    <td class="text-right">₱{{ number_format($sale->total_amount, 2) }}</td>
                    <td class="text-slate-500">{{ $sale->created_at->format('M j, Y') }}</td>
                    <td>
                        <form method="POST" action="{{ route('sales.restore', $sale) }}">
                            @csrf
                            <button class="text-brand-600 text-xs">Restore</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="p-8 text-center text-sm text-slate-400">No archived sales.</td></tr>
                @endforelse
            </tbody>
        </table>
</div>
    </div>

    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="p-4 border-b border-slate-100 flex items-center gap-2">
            <i data-lucide="shopping-bag" class="w-4 h-4 text-slate-500"></i>
            <p class="font-medium text-ink">Archived Orders</p>
        </div>
        <div class="overflow-x-auto">
<table class="w-full text-sm">
            <thead><tr class="text-left text-slate-500 bg-slate-50 border-b border-slate-200">
                <th class="p-4">Order #</th><th>Customer</th><th class="text-right">Total</th><th>Date</th><th></th>
            </tr></thead>
            <tbody>
                @forelse($archivedOrders as $order)
                <tr class="border-b border-slate-50">
                    <td class="p-4 font-medium text-ink">{{ $order->order_number }}</td>
                    <td>{{ $order->customer_name }}</td>
                    <td class="text-right">₱{{ number_format($order->total_amount, 2) }}</td>
                    <td class="text-slate-500">{{ $order->created_at->format('M j, Y') }}</td>
                    <td>
                        <form method="POST" action="{{ route('orders.restore', $order) }}">
                            @csrf
                            <button class="text-brand-600 text-xs">Restore</button>
                        </form>
                    </td>
                </tr>
                @empty
                <tr><td colspan="5" class="p-8 text-center text-sm text-slate-400">No archived orders.</td></tr>
                @endforelse
            </tbody>
        </table>
</div>
    </div>
</div>
@endsection
