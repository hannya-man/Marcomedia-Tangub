{{-- Materials with no type yet. Picking one puts it under Raw Materials or Materials. --}}
@if($unset->count())
<div class="mb-6 rounded-xl border border-amber-400 bg-amber-100 dark:bg-amber-900/30 p-4">
    <p class="text-sm font-medium text-ink dark:text-white">Not set up yet ({{ $unset->count() }})</p>
    <p class="text-xs text-slate-600 dark:text-slate-400 mb-2">
        Pick where each one belongs. <strong>Raw material</strong>: whole units like a roll, removed by hand when used up.
        <strong>Material</strong>: pieces you can count.
    </p>
    <div>
        @foreach($unset as $u)
            <div class="flex flex-wrap items-center justify-between gap-2 py-2 {{ $loop->first ? '' : 'border-t border-slate-200 dark:border-slate-700' }}">
                <p class="text-sm text-ink dark:text-white min-w-0">
                    {{ $u->name }}
                    <span class="text-xs text-slate-500 dark:text-slate-400">{{ $packs->fmt($u->stock_quantity) }} {{ $u->unit }}</span>
                </p>
                <div class="flex gap-2">
                    <form method="POST" action="{{ route('stock.type', $u) }}">
                        @csrf
                        <input type="hidden" name="inventory_type" value="raw">
                        <button type="submit" class="text-xs border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 rounded-lg px-3 py-1.5">
                            Raw material
                        </button>
                    </form>
                    <form method="POST" action="{{ route('stock.type', $u) }}">
                        @csrf
                        <input type="hidden" name="inventory_type" value="material">
                        <button type="submit" class="text-xs border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 rounded-lg px-3 py-1.5">
                            Material
                        </button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif
