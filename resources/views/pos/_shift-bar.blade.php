{{-- The person's shift (ShiftController): started with the cash already in the drawer, ended by
     counting it. The count is blind: what the drawer should hold shows only after it's typed. --}}
@php $field = 'text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500'; @endphp
<div x-data="{ ending: false }" class="mb-4">
@if($shift)
    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm">
        <p class="flex items-center gap-2 text-emerald-800">
            <i data-lucide="clock" class="w-4 h-4 flex-shrink-0"></i>
            <span>On shift since {{ $shift->opened_at->isToday() ? $shift->opened_at->format('h:i A') : $shift->opened_at->format('M j, h:i A') }}.
                The cash you take is counted when you end it.</span>
        </p>
        <button type="button" @click="ending = true; $nextTick(() => $refs.counted.focus())"
            title="Count the cash in the drawer and end your shift"
            class="inline-flex items-center gap-1.5 rounded-lg border border-emerald-300 bg-white px-3 py-1.5 text-xs font-medium text-emerald-800 hover:bg-emerald-100">
            <i data-lucide="log-out" class="w-3.5 h-3.5"></i> End shift
        </button>
    </div>

    <div x-show="ending" style="display:none;" x-transition.opacity class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4"
         @click.self="ending = false" @keydown.escape.window="ending = false">
        <form method="POST" action="{{ route('shifts.close', $shift) }}" class="bg-white rounded-lg shadow-lg border border-slate-200 w-full max-w-sm p-6">
            @csrf
            <p class="text-lg font-semibold text-ink mb-2">Count the cash</p>
            <p class="text-sm text-slate-500 mb-4">
                Count all the cash in the drawer now, bills and coins, and type the total. After you press End shift,
                you'll see if it matches what the drawer should have.
            </p>
            <label for="counted-cash" class="block text-xs font-medium text-slate-600 mb-1.5">Cash in the drawer</label>
            <input id="counted-cash" x-ref="counted" type="number" name="actual_cash" step="0.01" min="0" required placeholder="0.00" class="w-full {{ $field }}">
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 mt-5">
                <button type="button" @click="ending = false" class="border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-md px-4 py-2">Cancel</button>
                <button type="submit" class="bg-ink hover:bg-slate-800 text-white text-sm font-medium rounded-md px-4 py-2">End shift</button>
            </div>
        </form>
    </div>
@else
    <form method="POST" action="{{ route('shifts.open') }}" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm">
        @csrf
        <p class="flex items-center gap-2 text-slate-600">
            <i data-lucide="clock" class="w-4 h-4 flex-shrink-0 text-slate-400"></i>
            <span>You're not on shift. Start one so the cash you take is counted at the end of the day.</span>
        </p>
        <div class="flex items-center gap-2">
            <label for="opening-cash" class="sr-only">Cash in the drawer now</label>
            <input id="opening-cash" type="number" name="opening_cash" step="0.01" min="0" placeholder="Cash in drawer now"
                   title="The cash already in the drawer before your first sale (your change fund). Leave it empty if there's none."
                   class="w-44 {{ $field }} py-1.5">
            <button type="submit" title="Start your shift"
                class="inline-flex items-center gap-1.5 rounded-lg bg-ink px-3 py-1.5 text-xs font-medium text-white hover:bg-slate-800">
                <i data-lucide="play" class="w-3.5 h-3.5"></i> Start shift
            </button>
        </div>
    </form>
@endif
</div>
