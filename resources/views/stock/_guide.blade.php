{{--
    The plain-words guide at the top of the Continuous or Discrete page: what the page is for, and
    which button to press for each everyday job. $kind is 'continuous' or 'discrete'. It can be
    hidden (remembered on this device) and shown again with "How does this page work?".
--}}
@php
    $isCont = $kind === 'continuous';
    $tasks = $isCont ? [
        ['package-plus', 'New stock arrived', 'Press <strong>Add stock</strong> on the material and type how much came in.'],
        ['scissors', 'You used some for a job', 'Press <strong>Record use</strong> and type how much you used.'],
        ['triangle-alert', 'Some got damaged or wasted', 'Press <strong>Report damage</strong> and say what happened.'],
        ['calculator', 'The number on screen is wrong', 'Press <strong>Fix count</strong> and type what is really there.'],
    ] : [
        ['package-plus', 'New stock arrived', 'Press <strong>Add stock</strong> on the material and type how many came in.'],
        ['shopping-cart', 'You sold something', 'Nothing to do: the pieces it uses come out by themselves.'],
        ['triangle-alert', 'Pieces got damaged', 'Press <strong>Report damage</strong> and say what happened.'],
        ['calculator', 'The number on screen is wrong', 'Press <strong>Fix count</strong> and type how many are really there.'],
    ];
@endphp
<div x-data="{
        guide: true,
        key: 'stock-guide-{{ $kind }}',
        // Remembered on this device. Private browsing (storage refused): always shown.
        init() { try { this.guide = localStorage.getItem(this.key) !== 'hidden' } catch (e) {} },
        setGuide(on) {
            this.guide = on;
            try { on ? localStorage.removeItem(this.key) : localStorage.setItem(this.key, 'hidden') } catch (e) {}
        },
     }"
     class="mb-6">
    <div x-show="guide" class="rounded-xl border border-brand-200 bg-brand-50/60 p-5">
        <div class="flex items-start justify-between gap-3">
            <div class="flex items-start gap-3 min-w-0">
                <div class="w-9 h-9 rounded-lg bg-white text-brand-600 border border-brand-200 flex items-center justify-center flex-shrink-0">
                    <i data-lucide="{{ $isCont ? 'ruler' : 'boxes' }}" class="w-4 h-4"></i>
                </div>
                <div class="min-w-0">
                    <p class="font-semibold text-ink">What is this page for?</p>
                    <p class="text-sm text-slate-600 mt-0.5 max-w-3xl">
                        @if($isCont)
                            Materials you <strong>measure</strong> instead of count, like fabric, ink, thread and tarpaulin.
                            You use part of a roll or bottle at a time, so the system can't know how much was used: you record it.
                        @else
                            Materials you <strong>count one by one</strong>, like mug blanks, PVC cards and keychains.
                            When you sell an item, the pieces it's made from come out of stock by themselves.
                        @endif
                    </p>
                </div>
            </div>
            <button type="button" @click="setGuide(false)"
                    class="flex-shrink-0 text-xs text-slate-500 hover:text-ink whitespace-nowrap">Hide guide</button>
        </div>

        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 mt-5 mb-2">What do you want to do?</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-3">
            @foreach($tasks as [$icon, $when, $how])
                <div class="rounded-lg bg-white border border-slate-200 p-3 flex gap-3">
                    <i data-lucide="{{ $icon }}" class="w-5 h-5 text-brand-600 flex-shrink-0 mt-0.5"></i>
                    <div>
                        <p class="text-sm font-medium text-ink">{{ $when }}</p>
                        <p class="text-xs text-slate-600 mt-0.5">{!! $how !!}</p>
                    </div>
                </div>
            @endforeach
        </div>

        <p class="text-xs text-slate-500 mt-4">
            @if($isCont)
                <strong>Pack</strong> = one roll, bottle or bag. The <strong>pack in use</strong> is the open one; the others wait unopened, in the store or the warehouse.
                Rolls of a set size, like tarpaulin, are used by the foot when a job is sold.
            @else
                <strong>Pack</strong> = one box or bag of pieces. The <strong>pack in use</strong> is the open one; the others wait unopened, in the store or the warehouse.
                Boards like sintra are <strong>cut</strong> into ready pieces: press <strong>Cut boards</strong> on the board.
            @endif
        </p>
    </div>

    <button type="button" x-show="!guide" style="display:none;"
            @click="setGuide(true)"
            class="inline-flex items-center gap-1.5 text-sm text-brand-600 hover:underline">
        <i data-lucide="circle-help" class="w-4 h-4"></i> How does this page work?
    </button>
</div>
