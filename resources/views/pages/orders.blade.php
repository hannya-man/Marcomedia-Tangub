@extends('layouts.app')
@section('title', 'Orders')

@section('content')
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="flex items-center justify-between p-4 border-b border-slate-100 dark:border-slate-700">
            <p class="font-medium text-ink dark:text-white">All Orders</p>
            <select class="text-xs rounded-lg border-slate-300 dark:bg-slate-700 dark:border-slate-600 dark:text-white">
                <option>All Statuses</option><option>Pending</option><option>Processing</option><option>Completed</option><option>Cancelled</option>
            </select>
        </div>
        <table class="w-full text-sm">
            <thead><tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
                <th class="p-4">Order #</th><th>Customer</th><th>Items</th><th>Total</th><th>Status</th>
            </tr></thead>
            <tbody>
                @forelse($orders as $order)
                <tr class="border-b border-slate-50 dark:border-slate-700 cursor-pointer {{ $selectedOrder && $selectedOrder->id === $order->id ? 'bg-brand-50 dark:bg-brand-600/10' : '' }}"
                    onclick="window.location='{{ route('orders.index', ['selected' => $order->id]) }}'">
                    <td class="p-4 font-medium text-ink dark:text-white">{{ $order->order_number }}</td>
                    <td>{{ $order->customer_name }}</td>
                    <td>{{ $order->item_count }}</td>
                    <td>₱{{ number_format($order->total_amount, 2) }}</td>
                    <td>
                        @php
                            $badge = match($order->status) {
                                'completed' => 'bg-emerald-100 text-emerald-700',
                                'processing' => 'bg-amber-100 text-amber-700',
                                'cancelled' => 'bg-red-100 text-red-700',
                                default => 'bg-slate-100 text-slate-600',
                            };
                        @endphp
                        <span class="px-2 py-0.5 rounded-full text-xs {{ $badge }}">{{ ucfirst($order->status) }}</span>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="5" class="p-8 text-center text-sm text-slate-400">
                        No orders yet. Orders placed through the POS or storefront will show up here.
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5">
        @if($selectedOrder)
            <p class="font-medium text-ink dark:text-white mb-1">Order {{ $selectedOrder->order_number }}</p>
            <p class="text-xs text-slate-500 mb-4">{{ $selectedOrder->customer_name }} · {{ $selectedOrder->created_at->format('M j, Y') }}</p>
            <div class="text-sm border-t border-b border-slate-100 dark:border-slate-700 py-3 mb-3">
                <p class="text-slate-600 dark:text-slate-300 whitespace-pre-line">{{ $selectedOrder->items_summary ?: 'No item breakdown recorded.' }}</p>
            </div>
            <div class="flex justify-between text-sm font-semibold text-ink dark:text-white mb-4">
                <span>Total</span><span>₱{{ number_format($selectedOrder->total_amount, 2) }}</span>
            </div>

            @if(in_array($selectedOrder->status, ['pending', 'processing']))
            <div class="flex gap-2">
                <form method="POST" action="{{ route('orders.complete', $selectedOrder) }}" class="flex-1">
                    @csrf
                    <button type="submit" class="w-full bg-brand-600 text-white text-xs rounded-lg py-2">Mark Completed</button>
                </form>
                <form method="POST" action="{{ route('orders.cancel', $selectedOrder) }}" class="flex-1"
                      onsubmit="return confirm('Cancel this order? This cannot be undone.')">
                    @csrf
                    <button type="submit" class="w-full border border-slate-300 dark:border-slate-600 text-xs rounded-lg py-2 text-slate-600 dark:text-slate-300">Cancel Order</button>
                </form>
            </div>
            @else
                <p class="text-xs text-slate-400">This order is {{ $selectedOrder->status }} — no further action available.</p>
            @endif
        @else
            <p class="text-sm text-slate-400">Select an order from the list to see its details.</p>
        @endif
    </div>
</div>
@endsection
