@extends('layouts.app')
@section('title', 'Inventory Archive')

@section('content')
<a href="{{ session('inventory.list_url', route('inventory.index')) }}" class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 hover:bg-slate-50 rounded-lg px-4 py-2 mb-4 shadow-sm">
    <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to Inventory
</a>

<div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
    <div class="p-4 border-b border-slate-100 flex items-center gap-2">
        <i data-lucide="archive" class="w-4 h-4 text-slate-500"></i>
        <p class="font-medium text-ink">Archived Products</p>
    </div>
    <div class="overflow-x-auto">
<table class="w-full text-sm">
        <thead><tr class="text-left text-slate-500 bg-slate-50 border-b border-slate-200">
            <th class="p-4">Product</th><th>SKU</th><th>Category</th><th>Archived</th><th></th>
        </tr></thead>
        <tbody>
            @forelse($archivedProducts as $product)
            <tr class="border-b border-slate-50">
                <td class="p-4 font-medium text-ink">{{ $product->name }}</td>
                <td class="text-slate-500">{{ $product->sku }}</td>
                <td class="text-slate-500">{{ $product->category->name ?? 'Uncategorized' }}</td>
                <td class="text-slate-500">{{ $product->archived_at?->format('M j, Y') }}</td>
                <td>
                    {{-- Only an owner deletes a product, and brings one back --}}
                    @if(auth()->user()->role === 'admin')
                    <form method="POST" action="{{ route('inventory.restore', $product) }}">
                        @csrf
                        <button class="text-brand-600 text-xs">Restore</button>
                    </form>
                    @endif
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
