@extends('layouts.app')
@section('title', 'Raw Materials')

@section('content')
<a href="{{ route('inventory.index') }}" class="text-xs text-brand-600 flex items-center gap-1 mb-4">
    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to Inventory
</a>

<div x-data="{ addingMaterial: false, expanded: null, confirmingDelete: null }" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="flex items-center justify-between p-4 border-b border-slate-100 dark:border-slate-700">
            <div>
                <p class="font-medium text-ink dark:text-white">Raw Materials</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">Fabric rolls, blanks, and anything else consumed to produce finished stock.</p>
            </div>
            <div class="flex gap-2 flex-shrink-0">
                <a href="{{ route('materials.archive') }}" class="text-xs px-3 py-2 border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded-lg flex items-center gap-1.5">
                    <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive ({{ $archivedCount }})
                </a>
                <button @click="addingMaterial = true" class="text-xs px-3 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg flex items-center gap-1.5">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Material
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
                <th class="p-4">Material</th><th class="text-right">Stock</th><th>Unit</th><th>Used By</th><th>Status</th><th>Restock</th><th></th>
            </tr></thead>
            <tbody>
                @forelse($materials as $material)
                @php $status = $material->stock_status; @endphp
                <tr class="border-b border-slate-50 dark:border-slate-700">
                    <td class="p-4 font-medium text-ink dark:text-white">{{ $material->name }}</td>
                    <td class="text-right">{{ rtrim(rtrim(number_format($material->stock_quantity, 2), '0'), '.') }}</td>
                    <td class="text-slate-500 dark:text-slate-400">{{ $material->unit }}</td>
                    <td>
                        @if($material->variants_count > 0)
                        <button type="button" @click="expanded = expanded === {{ $material->id }} ? null : {{ $material->id }}"
                            class="text-brand-600 hover:underline flex items-center gap-1">
                            {{ $material->variants_count }} size(s)
                            <i data-lucide="chevron-down" class="w-3 h-3 transition-transform" :class="expanded === {{ $material->id }} ? 'rotate-180' : ''"></i>
                        </button>
                        @else
                            <span class="text-slate-400">Not linked yet</span>
                        @endif
                    </td>
                    <td>
                        <span class="px-2 py-1 rounded-full text-xs font-medium
                            {{ $status=='out_of_stock' ? 'bg-red-100 text-red-700' : ($status=='low_stock' ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700') }}">
                            {{ str_replace('_',' ', $status) }}
                        </span>
                    </td>
                    <td>
                        <form method="POST" action="{{ route('materials.restock', $material) }}" class="flex gap-1.5">
                            @csrf
                            <input type="number" step="0.01" name="quantity" min="0.01" placeholder="qty" class="w-20 text-xs rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-2 py-1.5">
                            <button title="Restock" class="text-xs bg-brand-600 hover:bg-brand-700 text-white rounded-lg px-3 py-1.5 flex items-center justify-center">
                                <i data-lucide="plus" class="w-3.5 h-3.5"></i>
                            </button>
                        </form>
                    </td>
                    <td>
                        <button type="button" title="Delete material"
                            @click="confirmingDelete = { id: {{ $material->id }}, name: {{ json_encode($material->name) }} }"
                            class="w-8 h-8 rounded-lg border border-red-200 dark:border-red-900 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center justify-center">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        </button>
                    </td>
                </tr>
                @if($material->variants_count > 0)
                <tr x-show="expanded === {{ $material->id }}" style="display:none;">
                    <td colspan="7" class="px-4 pb-4 bg-slate-50 dark:bg-slate-700/30">
                        <p class="text-xs font-medium text-slate-500 dark:text-slate-400 uppercase tracking-wide mb-2 pt-2">Sizes made from this material</p>
                        <div class="flex flex-wrap gap-2">
                            @foreach($material->variants as $variant)
                                <span class="inline-flex items-center gap-1 text-xs bg-white dark:bg-slate-800 border border-slate-200 dark:border-slate-600 rounded-full px-2.5 py-1">
                                    <i data-lucide="shirt" class="w-3 h-3 text-slate-400"></i>
                                    {{ $variant->product->name ?? 'Product' }} — {{ $variant->variant_name }}
                                    <span class="text-slate-400">({{ rtrim(rtrim(number_format($variant->pivot->quantity_per_unit, 3), '0'), '.') }} {{ $material->unit }})</span>
                                </span>
                            @endforeach
                        </div>
                    </td>
                </tr>
                @endif
                @empty
                <tr><td colspan="7" class="p-8 text-center text-sm text-slate-400">No raw materials tracked yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 h-fit">
        <p class="font-medium text-ink dark:text-white mb-2">How this works</p>
        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed mb-3">
            A material's stock only goes down when you <strong>restock a finished
            size</strong> on the Inventory page (that's when fabric actually gets
            cut) — not when a shirt is sold off the shelf. Selling just draws
            down the already-cut finished stock.
        </p>
        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
            To link a size to a material, edit that size's row when adding a
            product and add each material it uses along with how much one
            unit consumes (e.g. a Medium shirt = 1.2 meters of fabric AND
            0.05 liters of ink). Click "Used By" on any material above to
            see every size currently linked to it.
        </p>
    </div>

    {{-- ADD MATERIAL MODAL --}}
    <div x-show="addingMaterial" style="display:none;" x-transition.opacity class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="addingMaterial = false">
        <div x-show="addingMaterial" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 w-full max-w-sm p-6">
            <p class="text-lg font-semibold text-ink dark:text-white mb-4">Add Material</p>
            <form method="POST" action="{{ route('materials.store') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Material Name</label>
                    <input type="text" name="name" required placeholder="e.g. Sublimation Poly Fabric - White"
                        class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Unit</label>
                        <input type="text" name="unit" required placeholder="e.g. meters"
                            class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Starting Stock</label>
                        <input type="number" step="0.01" name="stock_quantity" required placeholder="0"
                            class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Low Stock Warning At</label>
                    <input type="number" step="0.01" name="low_stock_threshold" placeholder="5"
                        class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="addingMaterial = false" class="bg-red-600 hover:bg-red-700 text-white text-sm rounded-md px-4 py-2">Cancel</button>
                    <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm rounded-md px-4 py-2">Save Material</button>
                </div>
            </form>
        </div>
    </div>

    {{-- SHADCN-STYLE ALERT DIALOG — Delete confirmation --}}
    <div x-show="confirmingDelete !== null" style="display:none;" x-transition.opacity class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="confirmingDelete = null">
        <div x-show="confirmingDelete !== null" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 w-full max-w-md p-6">
            <p class="text-lg font-semibold text-ink dark:text-white mb-2">Delete this material?</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                <span x-text="confirmingDelete?.name"></span> will be moved to the Archive, not permanently deleted — its link to any sizes and its stock history stay intact, and you can restore it any time.
            </p>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button @click="confirmingDelete = null"
                    class="border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium rounded-md px-4 py-2">
                    Cancel
                </button>
                <button @click="document.getElementById('delete-material-form-' + confirmingDelete.id).submit()"
                    class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">
                    Delete
                </button>
            </div>
        </div>
    </div>

    @foreach($materials as $material)
        <form id="delete-material-form-{{ $material->id }}" method="POST" action="{{ route('materials.destroy', $material) }}" class="hidden">
            @csrf @method('DELETE')
        </form>
    @endforeach
</div>
@endsection
