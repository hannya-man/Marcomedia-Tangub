@extends('layouts.app')
@section('title', 'Materials Archive')

@section('content')
<a href="{{ route('stock.' . $from) }}"
   class="inline-flex items-center gap-2 text-sm font-medium text-slate-700 dark:text-slate-200 bg-white dark:bg-slate-800 border border-slate-300 dark:border-slate-600 hover:bg-slate-50 dark:hover:bg-slate-700 rounded-lg px-4 py-2 mb-4 shadow-sm">
    <i data-lucide="arrow-left" class="w-4 h-4"></i> Back to {{ $from === 'discrete' ? 'Discrete Materials' : 'Continuous Raw Materials' }}
</a>

<div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
    <div class="p-4 border-b border-slate-100 dark:border-slate-700 flex items-center gap-2">
        <i data-lucide="archive" class="w-4 h-4 text-slate-500"></i>
        <p class="font-medium text-ink dark:text-white">Archived Materials</p>
    </div>
    <div class="overflow-x-auto">
    <table class="w-full text-sm">
        <thead><tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
            <th class="p-4">Material</th><th>Type</th><th class="text-right">Stock</th><th>Unit</th><th>Archived</th><th></th>
        </tr></thead>
        <tbody>
            @forelse($archivedMaterials as $material)
            <tr class="border-b border-slate-50 dark:border-slate-700">
                <td class="p-4 font-medium text-ink dark:text-white">{{ $material->name }}</td>
                <td class="text-slate-500 dark:text-slate-400">{{ ['continuous' => 'Continuous', 'discrete' => 'Discrete'][$material->inventory_type] ?? 'Not set' }}</td>
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
                <tr><td colspan="6" class="p-8 text-center text-sm text-slate-400">Nothing archived yet.</td></tr>
            @endforelse
        </tbody>
    </table>
    </div>
</div>
@endsection
