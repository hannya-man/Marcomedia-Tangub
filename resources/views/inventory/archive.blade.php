@extends('layouts.app')
@section('title', 'Inventory Archive')

@section('content')
<a href="{{ route('inventory.index') }}" class="text-xs text-brand-600 flex items-center gap-1 mb-4">
    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to Inventory
</a>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <div class="p-4 border-b border-slate-100 dark:border-slate-700 flex items-center gap-2">
        <i data-lucide="archive" class="w-4 h-4 text-slate-500"></i>
        <p class="font-medium text-ink dark:text-white">Archived Products</p>
    </div>
    <div class="overflow-x-auto">
<table class="w-full text-sm">
        <thead><tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
            <th class="p-4">Product</th><th>SKU</th><th>Category</th><th>Archived</th><th></th>
        </tr></thead>
        <tbody>
            @forelse($archivedProducts as $product)
            <tr class="border-b border-slate-50 dark:border-slate-700">
                <td class="p-4 font-medium text-ink dark:text-white">{{ $product->name }}</td>
                <td class="text-slate-500 dark:text-slate-400">{{ $product->sku }}</td>
                <td class="text-slate-500 dark:text-slate-400">{{ $product->category->name ?? 'Uncategorized' }}</td>
                <td class="text-slate-500 dark:text-slate-400">{{ $product->archived_at?->format('M j, Y') }}</td>
                <td>
                    <form method="POST" action="{{ route('inventory.restore', $product) }}">
                        @csrf
                        <button class="text-brand-600 text-xs">Restore</button>
                    </form>
                </td>
            </tr>
            @empty
                <tr><td colspan="5" class="p-8 text-center text-sm text-slate-400">Nothing archived yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
</div>
@endsection
