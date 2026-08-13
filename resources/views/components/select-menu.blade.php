{{--
    Shadcn-style dropdown, rebuilt in Alpine.js since this project is Blade,
    not React (shadcn/ui itself is a React + Radix component library — this
    recreates its exact visual language: rounded-md panel, border, shadow-md,
    fade+zoom entrance, hover highlight, check mark on the selected item).

    Renders a hidden <input type="hidden"> with the given `name` so it drops
    straight into any existing form/filter exactly like a native <select>
    would — no server-side code needs to change.

    Usage:
        <x-select-menu name="category_id" :options="['' => 'All categories', 1 => 'Apparel']"
            :selected="request('category_id')" placeholder="All categories" onchange="$el.closest('form').submit()" />
--}}
@props([
    'name',
    'options' => [],
    'selected' => null,
    'placeholder' => 'Select...',
    'onchange' => '',
])

@php
    // Passed as an array of {value, label} pairs instead of a keyed
    // object — avoids PHP-array-key-type (int vs string) mismatches
    // turning into JS object-key lookup misses, which was causing the
    // dropdown to silently fail to reflect the actual selected value.
    $optionPairs = collect($options)->map(fn ($label, $value) => [
        'value' => (string) $value,
        'label' => $label,
    ])->values()->all();
@endphp

<div x-data="{
        open: false,
        value: @js((string) ($selected ?? '')),
        options: @js($optionPairs),
        label() {
            const match = this.options.find(o => o.value === this.value);
            return match ? match.label : @js($placeholder);
        },
     }"
     @click.outside="open = false"
     class="relative inline-block w-full">

    <input type="hidden" name="{{ $name }}" x-model="value">

    <button type="button" @click="open = !open"
        class="w-full flex items-center justify-between gap-2 text-sm rounded-md border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 shadow-sm hover:border-brand-400 dark:hover:border-brand-400 transition-colors">
        <span x-text="label()" class="truncate"></span>
        <i data-lucide="chevron-down" class="w-4 h-4 text-slate-400 flex-shrink-0 transition-transform duration-150" :class="open ? 'rotate-180' : ''"></i>
    </button>

    <div x-show="open"
         x-transition:enter="transition ease-out duration-150"
         x-transition:enter-start="opacity-0 scale-95"
         x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100"
         x-transition:leave-start="opacity-100 scale-100"
         x-transition:leave-end="opacity-0 scale-95"
         style="display:none;"
         class="absolute z-50 mt-1.5 w-full min-w-[10rem] overflow-hidden rounded-md border border-slate-200 dark:border-slate-700 bg-white dark:bg-slate-800 p-1 shadow-md">
        <template x-for="opt in options" :key="opt.value">
            <button type="button"
                @click="value = opt.value; open = false; {!! $onchange !!}"
                class="w-full flex items-center gap-2 rounded-sm px-2 py-1.5 text-sm text-left text-slate-700 dark:text-slate-200 hover:bg-slate-100 dark:hover:bg-slate-700 transition-colors"
                :class="value === opt.value ? 'bg-slate-50 dark:bg-slate-700/60 font-medium' : ''">
                <i data-lucide="check" class="w-3.5 h-3.5 text-brand-600 flex-shrink-0" :class="value === opt.value ? 'opacity-100' : 'opacity-0'"></i>
                <span x-text="opt.label"></span>
            </button>
        </template>
    </div>
</div>
