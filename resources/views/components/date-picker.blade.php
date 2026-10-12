{{--
    Shadcn-style date picker: a button trigger (calendar icon + selected
    date, or a placeholder) that opens a popover containing a month-grid
    calendar — recreating shadcn's Calendar + Popover combo pattern in
    Alpine.js since this is a Blade app, not React.

    Renders a hidden <input type="hidden"> with the given `name`, so it
    drops into any existing filter form exactly like a native date input.

    Usage:
        <x-date-picker name="date_from" :selected="request('date_from')" placeholder="From date" />

    `onchange` works like x-select-menu's: it runs when a different date is
    picked or the date is cleared, with the hidden input already holding it.
--}}
@props([
    'name',
    'selected' => null,
    'placeholder' => 'Pick a date',
    'onchange' => '',
])

<div x-data="datePicker(@js($selected), @js($placeholder))" @click.outside="open = false" class="relative inline-block">
    <input type="hidden" name="{{ $name }}" x-model="value" x-ref="input">

    <button type="button" @click="open = !open"
        class="flex items-center gap-2 text-sm rounded-md border border-slate-300 bg-white px-3 py-2 shadow-sm hover:border-brand-400 transition-colors">
        <i data-lucide="calendar" class="w-4 h-4 text-slate-400 flex-shrink-0"></i>
        <span x-text="label()" :class="value ? '' : 'text-slate-400'"></span>
    </button>

    <div x-show="open"
         x-transition:enter="transition ease-out duration-150" x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
         x-transition:leave="transition ease-in duration-100" x-transition:leave-start="opacity-100 scale-100" x-transition:leave-end="opacity-0 scale-95"
         style="display:none;"
         class="absolute z-50 mt-1.5 w-64 rounded-md border border-slate-200 bg-white p-3 shadow-md">
        <div class="flex items-center justify-between mb-2">
            <button type="button" @click="shiftMonth(-1)" class="w-6 h-6 rounded hover:bg-slate-100 flex items-center justify-center text-slate-500">
                <i data-lucide="chevron-left" class="w-3.5 h-3.5"></i>
            </button>
            <p class="text-xs font-medium text-ink" x-text="monthLabel()"></p>
            <button type="button" @click="shiftMonth(1)" class="w-6 h-6 rounded hover:bg-slate-100 flex items-center justify-center text-slate-500">
                <i data-lucide="chevron-right" class="w-3.5 h-3.5"></i>
            </button>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center text-[10px] text-slate-400 mb-1">
            <span>S</span><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span>
        </div>
        <div class="grid grid-cols-7 gap-1">
            <template x-for="blank in offset()" :key="'b'+blank"><div></div></template>
            <template x-for="day in daysInMonth()" :key="day.date">
                <button type="button" @click="open = false; if (value !== day.date) { value = $refs.input.value = day.date; {!! $onchange !!} }"
                    class="aspect-square rounded text-xs"
                    :class="value === day.date ? 'bg-brand-600 text-white font-semibold' : (day.isToday ? 'border border-brand-400 text-brand-700' : 'hover:bg-slate-100 text-ink')"
                    x-text="day.label"></button>
            </template>
        </div>
        <button type="button" x-show="value" @click="open = false; value = $refs.input.value = ''; {!! $onchange !!}" class="mt-2 text-[11px] text-slate-400 hover:text-slate-600">Clear date</button>
    </div>
</div>

{{-- Defined once per page, so the placeholder is a parameter: written in here, every picker would show the first one's --}}
@once
<script>
function datePicker(initial, placeholder) {
    return {
        open: false,
        value: initial || '',
        placeholder,
        viewMonth: (() => {
            const base = initial ? new Date(initial + 'T00:00:00') : new Date();
            return { year: base.getFullYear(), month: base.getMonth() };
        })(),
        label() {
            if (!this.value) return this.placeholder;
            const d = new Date(this.value + 'T00:00:00');
            return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        },
        monthLabel() {
            return new Date(this.viewMonth.year, this.viewMonth.month, 1).toLocaleString('default', { month: 'long', year: 'numeric' });
        },
        offset() {
            return new Date(this.viewMonth.year, this.viewMonth.month, 1).getDay();
        },
        daysInMonth() {
            const { year, month } = this.viewMonth;
            const days = new Date(year, month + 1, 0).getDate();
            const pad = n => String(n).padStart(2, '0');
            const todayStr = new Date().toISOString().slice(0, 10);
            return Array.from({ length: days }, (_, i) => {
                const day = i + 1;
                const date = `${year}-${pad(month + 1)}-${pad(day)}`;
                return { label: day, date, isToday: date === todayStr };
            });
        },
        shiftMonth(delta) {
            let { year, month } = this.viewMonth;
            month += delta;
            if (month < 0) { month = 11; year--; }
            if (month > 11) { month = 0; year++; }
            this.viewMonth = { year, month };
        },
    }
}
</script>
@endonce
