@extends('layouts.app')
@section('title', 'Materials Archive')

@section('content')
<a href="{{ route('inventory.materials') }}" class="text-xs text-brand-600 flex items-center gap-1 mb-4">
    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to Raw Materials
</a>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <div class="p-4 border-b border-slate-100 dark:border-slate-700 flex items-center gap-2">
        <i data-lucide="archive" class="w-4 h-4 text-slate-500"></i>
        <p class="font-medium text-ink dark:text-white">Archived Materials</p>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
            <th class="p-4">Material</th><th class="text-right">Stock</th><th>Unit</th><th>Archived</th><th></th>
        </tr></thead>
        <tbody>
            @forelse($archivedMaterials as $material)
            <tr class="border-b border-slate-50 dark:border-slate-700">
                <td class="p-4 font-medium text-ink dark:text-white">{{ $material->name }}</td>
                <td class="text-right">{{ rtrim(rtrim(number_format($material->stock_quantity, 2), '0'), '.') }}</td>
                <td class="text-slate-500 dark:text-slate-400">{{ $material->unit }}</td>
                <td class="text-slate-500 dark:text-slate-400">{{ $material->archived_at?->format('M j, Y') }}</td>
                <td>
                    <form method="POST" action="{{ route('materials.restore', $material) }}">
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
