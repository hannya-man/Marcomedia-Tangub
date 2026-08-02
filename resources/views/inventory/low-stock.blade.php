@extends('layouts.app')
@section('title', 'Low Stock Report')

@section('content')
<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700">
    <div class="overflow-x-auto">
<table class="w-full text-sm">
        <thead>
            <tr class="text-left text-slate-500 dark:text-slate-400 border-b border-slate-100 dark:border-slate-700">
                <th class="p-4">Product</th><th>Variant</th><th class="text-right">Stock</th>
                <th class="text-right">Threshold</th><th class="text-right">Reorder Point</th>
            </tr>
        </thead>
        <tbody>
        @foreach($variants as $v)
            <tr class="border-b border-slate-50">
                <td class="p-4">{{ $v->product->name }}</td>
                <td>{{ $v->variant_name }}</td>
                <td class="text-right font-medium {{ $v->stock_quantity <= 0 ? 'text-red-600' : 'text-amber-600' }}">{{ $v->stock_quantity }}</td>
                <td class="text-right text-slate-500 dark:text-slate-400">{{ $v->low_stock_threshold }}</td>
                <td class="text-right text-slate-500 dark:text-slate-400">{{ $v->reorder_point }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
</div>
@endsection
