@extends('layouts.app')
@section('title', 'Categories')

@section('content')
<a href="{{ route('inventory.index') }}" class="text-xs text-brand-600 flex items-center gap-1 mb-4">
    <i data-lucide="arrow-left" class="w-3.5 h-3.5"></i> Back to Inventory
</a>

<div x-data="{ adding: false, confirmingDelete: null }" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 overflow-hidden">
        <div class="flex items-center justify-between p-4 border-b border-slate-100 dark:border-slate-700">
            <p class="font-medium text-ink dark:text-white">Categories</p>
            <div class="flex gap-2">
                <a href="{{ route('categories.archive') }}" class="text-xs px-3 py-2 border border-slate-300 dark:border-slate-600 text-slate-600 dark:text-slate-300 rounded-lg flex items-center gap-1.5">
                    <i data-lucide="archive" class="w-3.5 h-3.5"></i> Archive ({{ $archivedCount }})
                </a>
                <button @click="adding = true" class="text-xs px-3 py-2 bg-brand-600 hover:bg-brand-700 text-white rounded-lg flex items-center gap-1.5">
                    <i data-lucide="plus" class="w-3.5 h-3.5"></i> Add Category
                </button>
            </div>
        </div>
        <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead><tr class="text-left text-slate-500 dark:text-slate-400 bg-slate-50 dark:bg-slate-700/50 border-b border-slate-200 dark:border-slate-700">
                <th class="p-4">Category</th><th>Products</th><th></th>
            </tr></thead>
            <tbody>
                @forelse($categories as $category)
                <tr class="border-b border-slate-50 dark:border-slate-700">
                    <td class="p-4 font-medium text-ink dark:text-white">{{ $category->name }}</td>
                    <td class="text-slate-500 dark:text-slate-400">{{ $category->products_count }} product(s)</td>
                    <td>
                        <button type="button" title="Delete category"
                            @click="confirmingDelete = { id: {{ $category->id }}, name: {{ json_encode($category->name) }} }"
                            class="w-8 h-8 rounded-lg border border-red-200 dark:border-red-900 text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 flex items-center justify-center">
                            <i data-lucide="trash-2" class="w-3.5 h-3.5"></i>
                        </button>
                    </td>
                </tr>
                @empty
                <tr><td colspan="3" class="p-8 text-center text-sm text-slate-400">No categories yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="bg-white dark:bg-slate-800 rounded-xl border border-slate-200 dark:border-slate-700 p-5 h-fit">
        <p class="font-medium text-ink dark:text-white mb-2">How this works</p>
        <p class="text-xs text-slate-500 dark:text-slate-400 leading-relaxed">
            Categories group your products for filtering (Apparel, Trophies,
            Customized Items, etc). Deleting one archives it instead of
            removing it — products already assigned keep their category,
            it just stops showing up as a choice for new products.
        </p>
    </div>

    {{-- ADD CATEGORY MODAL --}}
    <div x-show="adding" style="display:none;" x-transition.opacity class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="adding = false">
        <div x-show="adding" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 w-full max-w-sm p-6">
            <p class="text-lg font-semibold text-ink dark:text-white mb-4">Add Category</p>
            <form method="POST" action="{{ route('categories.store') }}" class="space-y-3">
                @csrf
                <div>
                    <label class="text-xs font-medium text-slate-600 dark:text-slate-300">Category Name</label>
                    <input type="text" name="name" required placeholder="e.g. Photo Printing"
                        class="mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" @click="adding = false" class="bg-red-600 hover:bg-red-700 text-white text-sm rounded-md px-4 py-2">Cancel</button>
                    <button type="submit" class="bg-brand-600 hover:bg-brand-700 text-white text-sm rounded-md px-4 py-2">Save Category</button>
                </div>
            </form>
        </div>
    </div>

    {{-- SHADCN-STYLE ALERT DIALOG — Delete confirmation --}}
    <div x-show="confirmingDelete !== null" style="display:none;" x-transition.opacity class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4" @click.self="confirmingDelete = null">
        <div x-show="confirmingDelete !== null" x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
             class="bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 w-full max-w-md p-6">
            <p class="text-lg font-semibold text-ink dark:text-white mb-2">Delete this category?</p>
            <p class="text-sm text-slate-500 dark:text-slate-400 mb-6">
                <span x-text="confirmingDelete?.name"></span> will be moved to the Archive, not permanently deleted — products already in it are unaffected, and you can restore it any time.
            </p>
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2">
                <button @click="confirmingDelete = null"
                    class="border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium rounded-md px-4 py-2">
                    Cancel
                </button>
                <button @click="document.getElementById('delete-category-form-' + confirmingDelete.id).submit()"
                    class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">
                    Delete
                </button>
            </div>
        </div>
    </div>

    @foreach($categories as $category)
        <form id="delete-category-form-{{ $category->id }}" method="POST" action="{{ route('categories.destroy', $category) }}" class="hidden">
            @csrf @method('DELETE')
        </form>
    @endforeach
</div>
@endsection
