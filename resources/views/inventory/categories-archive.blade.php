@extends('layouts.app')
@section('title', 'Categories Archive')

@section('content')
<a href="{{ route('inventory.categories') }}" class="text-xs text-brand-600 flex items-center gap-1 mb-4">
    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to Categories
</a>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <div class="p-4 border-b border-slate-100 dark:border-slate-700 flex items-center gap-2">
        <i data-lucide="archive" class="w-4 h-4 text-slate-500"></i>
        <p class="font-medium text-ink dark:text-white">Archived Categories</p>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
            <th class="p-4">Category</th><th>Products</th><th></th>
        </tr></thead>
        <tbody>
            @forelse($archivedCategories as $category)
            <tr class="border-b border-slate-50 dark:border-slate-700">
                <td class="p-4 font-medium text-ink dark:text-white">{{ $category->name }}</td>
                <td class="text-slate-500 dark:text-slate-400">{{ $category->products_count }} product(s)</td>
                <td>
                    <form method="POST" action="{{ route('categories.restore', $category) }}">
                        @csrf
                        <button class="text-brand-600 text-xs">Restore</button>
                    </form>
                </td>
            </tr>
            @empty
                <tr><td colspan="3" class="p-8 text-center text-sm text-slate-400">Nothing archived yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
