{{-- Materials with no type yet. Picking one puts it under Continuous or Discrete. --}}
@if($unset->count())
<div class="mb-6 rounded-xl border border-amber-400 bg-amber-100 p-4">
    <p class="text-sm font-medium text-ink">Not set up yet ({{ $unset->count() }})</p>
    <p class="text-xs text-slate-600 mb-2">
        Pick where each one belongs. <strong>Continuous</strong>: measured by length or volume, like fabric, thread or ink.
        <strong>Discrete</strong>: single pieces you can count, like PVC cards. Until you pick, sales deduct it like a discrete material.
    </p>
    <div>
        @foreach($unset as $u)
            <div class="flex flex-wrap items-center justify-between gap-2 py-2 {{ $loop->first ? '' : 'border-t border-slate-200' }}">
                <p class="text-sm text-ink min-w-0">
                    {{ $u->name }}
                    <span class="text-xs text-slate-500">{{ \App\Services\Inventory\StockAlertService::formatQty($u->stock_quantity) }} {{ $u->unit }}</span>
                </p>
                <div class="flex gap-2">
                    @foreach(['continuous' => 'Continuous', 'discrete' => 'Discrete'] as $value => $text)
                        <form method="POST" action="{{ route('stock.type', $u) }}">
                            @csrf
                            <input type="hidden" name="inventory_type" value="{{ $value }}">
                            <button type="submit" class="text-xs border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 rounded-lg px-3 py-1.5">
                                {{ $text }}
                            </button>
                        </form>
                    @endforeach
                </div>
            </div>
        @endforeach
    </div>
</div>
@endif
