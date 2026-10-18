{{-- One "made from" list on Add Product: each line a material and how much one takes. A counted
     (discrete) material takes whole pieces; a measured (continuous) one takes some of its unit.
     $list: the lines (an Alpine expression); $field: the start of their field names; $off: when
     they're hidden, and not sent. --}}
@php
    $counted = $materials->filter(fn ($m) => $m->inventory_type !== 'continuous');
    $measured = $materials->filter(fn ($m) => $m->inventory_type === 'continuous');
@endphp
<div class="space-y-1.5">
    <template x-for="(vm, j) in {{ $list }}" :key="j">
        <div class="grid grid-cols-12 gap-2 items-center">
            <div class="col-span-12 sm:col-span-7">
                <select :name="{{ $field }} + '[' + j + '][material_id]'" x-model="vm.material_id" :disabled="{{ $off }}" aria-label="Material"
                    class="w-full text-xs rounded-lg border border-slate-300 bg-white px-2 py-1.5">
                    <option value="">Pick a material</option>
                    @if($counted->isNotEmpty())
                        <optgroup label="Counted (whole pieces)">
                            @foreach($counted as $material)
                                <option value="{{ $material->id }}">{{ $material->name }} ({{ $material->unit }})</option>
                            @endforeach
                        </optgroup>
                    @endif
                    @if($measured->isNotEmpty())
                        <optgroup label="Measured (ink, fabric, rolls)">
                            @foreach($measured as $material)
                                <option value="{{ $material->id }}">{{ $material->name }} ({{ $material->unit }})</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
            </div>
            <div class="col-span-10 sm:col-span-4 flex items-center gap-1.5">
                <input type="number" :name="{{ $field }} + '[' + j + '][quantity_per_unit]'" x-model="vm.quantity_per_unit" :disabled="{{ $off }}"
                    :step="isCounted(vm.material_id) ? 1 : 0.001" :min="isCounted(vm.material_id) ? 1 : 0.001"
                    :placeholder="vm.material_id === '' ? 'Amount' : (isCounted(vm.material_id) ? 'How many' : 'How much')" aria-label="How much one takes"
                    class="w-full text-xs text-right rounded-lg border border-slate-300 bg-white px-2 py-1.5">
                <span class="w-16 flex-shrink-0 text-[11px] text-slate-500 truncate" x-text="unitLabel(vm.material_id)"></span>
            </div>
            <div class="col-span-2 sm:col-span-1 flex justify-end">
                <button type="button" @click="{{ $list }}.splice(j, 1)" title="Remove this material" aria-label="Remove this material"
                    class="w-7 h-7 rounded-lg text-red-500 hover:bg-red-50 flex items-center justify-center">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3.5 h-3.5" aria-hidden="true"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                </button>
            </div>
        </div>
    </template>
</div>
<button type="button" @click="{{ $list }}.push(line())"
    class="mt-1.5 text-[11px] text-brand-600 font-medium inline-flex items-center gap-1">
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="w-3 h-3" aria-hidden="true"><path d="M5 12h14"/><path d="M12 5v14"/></svg> Add a material
</button>
