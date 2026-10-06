{{--
    Dialogs for the Continuous and Discrete pages. $kind is 'continuous' or 'discrete'.
    One overlay holds every form; only the one matching `modal` shows. Each posts to its own route.
--}}
@php
    $isCont = $kind === 'continuous';
    $input = 'mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500';
    $label = 'text-xs font-medium text-slate-600 dark:text-slate-300';
    $btnCancel = 'border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium rounded-md px-4 py-2';
    $btnPrimary = 'bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-md px-4 py-2';
    $head = 'text-lg font-semibold text-ink dark:text-white';
    $sub = 'text-sm text-slate-500 dark:text-slate-400';
@endphp

<div x-show="modal !== null" style="display:none;" x-transition.opacity
     class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4 overflow-y-auto"
     @click.self="hide()" @keydown.escape.window="hide()">
    <div class="bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 w-full max-w-md p-6 my-8">

        {{-- Add a material of this type --}}
        <form x-show="modal === 'add'" style="display:none;" method="POST" action="{{ route('stock.store') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="inventory_type" value="{{ $kind }}">
            <div>
                <p class="{{ $head }}">{{ $isCont ? 'Add continuous raw material' : 'Add discrete material' }}</p>
                <p class="{{ $sub }}">{{ $isCont ? 'Measured by length or volume, like fabric, thread or ink.' : 'Single pieces you can count, like PVC cards or RFID chips.' }}</p>
            </div>
            <div>
                <label class="{{ $label }}">Name</label>
                <input type="text" name="name" required maxlength="150" :placeholder="sheet ? 'e.g. Sintra Board 3mm' : '{{ $isCont ? 'e.g. Sublimation Fabric Roll' : 'e.g. PVC Card Blank' }}'" class="{{ $input }}">
            </div>
            @if($isCont)
            <label class="flex items-start gap-2 rounded-lg border border-slate-200 dark:border-slate-700 px-3 py-2 cursor-pointer">
                <input type="checkbox" name="sheet" value="1" x-model="sheet" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span>
                    <span class="block text-sm text-ink dark:text-white">Cut from whole sheets</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Like sintra board. Each job takes its size from the open sheet, or a whole sheet. Stock is counted in sq ft.</span>
                </span>
            </label>
            <template x-if="sheet">
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="{{ $label }}">Sheet size (ft)</label>
                            <div class="flex items-center gap-2">
                                <input type="number" step="0.01" min="0.5" max="50" name="sheet_width" required placeholder="4" aria-label="Sheet width in feet" class="{{ $input }}">
                                <span class="mt-1 text-slate-400">x</span>
                                <input type="number" step="0.01" min="0.5" max="50" name="sheet_height" required placeholder="8" aria-label="Sheet height in feet" class="{{ $input }}">
                            </div>
                        </div>
                        <div>
                            <label class="{{ $label }}">Sheets on hand now (optional)</label>
                            <input type="number" step="1" min="0" max="500" name="sheets" class="{{ $input }}">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="{{ $label }}">Reorder at (sheets)</label>
                            <input type="number" step="0.5" min="0" max="500" name="reorder_sheets" placeholder="1" class="{{ $input }}">
                        </div>
                        <div>
                            <label class="{{ $label }}">Cost per sheet (optional)</label>
                            <input type="number" step="0.01" min="0" name="cost_per_sheet" class="{{ $input }}">
                        </div>
                    </div>
                    <p class="text-xs text-slate-400">Each sheet on hand becomes its own batch, with a batch number.</p>
                </div>
            </template>
            @endif
            <template x-if="!sheet">
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="{{ $label }}">Unit</label>
                            <input type="text" name="unit" required maxlength="20" placeholder="{{ $isCont ? 'meters, ml' : 'pcs' }}" class="{{ $input }}">
                        </div>
                        <div>
                            <label class="{{ $label }}">On hand now (optional)</label>
                            <input type="number" step="0.001" min="0" name="opening_stock" class="{{ $input }}">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="{{ $label }}">Reorder at</label>
                            <input type="number" step="0.001" min="0" name="low_stock_threshold" placeholder="5" class="{{ $input }}">
                        </div>
                        <div>
                            <label class="{{ $label }}">Cost per unit (optional)</label>
                            <input type="number" step="0.01" min="0" name="cost_per_unit" class="{{ $input }}">
                        </div>
                    </div>
                    <p class="text-xs text-slate-400">What's on hand becomes its first batch, with a batch number.</p>
                </div>
            </template>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">Save</button>
            </div>
        </form>

        {{-- Restock without a purchase order: becomes a new sealed batch. Sheet materials: one batch per sheet. --}}
        <form x-show="modal === 'restock'" style="display:none;" :action="m ? m.urls.restock : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">Restock</p>
                <p class="{{ $sub }}">
                    <span x-text="m ? m.name : ''"></span>.
                    <span x-text="m && m.sheet ? 'Each sheet becomes its own batch of ' + m.sheet.area + ' sq ft, with a batch number.' : 'The new stock becomes its own batch with a batch number.'"></span>
                </p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <template x-if="m && m.sheet">
                    <div>
                        <label class="{{ $label }}">How many sheets</label>
                        <input type="number" step="1" min="1" max="500" name="sheets" x-model="qty" required class="{{ $input }}">
                    </div>
                </template>
                <template x-if="!(m && m.sheet)">
                    <div>
                        <label class="{{ $label }}">How much (<span x-text="m ? m.unit : ''"></span>)</label>
                        <input type="number" step="0.001" min="0.001" name="quantity" x-model="qty" required class="{{ $input }}">
                    </div>
                </template>
                <div>
                    <label class="{{ $label }}">Put it in</label>
                    <select name="location" class="{{ $input }}">
                        <option value="store">Store</option>
                        <option value="warehouse">Warehouse</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="{{ $label }}" x-text="m && m.sheet ? 'Cost per sheet (optional)' : 'Cost per unit (optional)'"></label>
                <input type="number" step="0.01" min="0" :name="m && m.sheet ? 'cost_per_sheet' : 'cost_per_unit'" class="{{ $input }}">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">Restock</button>
            </div>
        </form>

        @if($isCont)
        {{-- Continuous only: log what was pulled for use --}}
        <form x-show="modal === 'pull'" style="display:none;" :action="m ? m.urls.pull : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">Pull for use</p>
                <p class="{{ $sub }}">
                    <span x-text="m ? m.name : ''"></span>: <span x-text="m ? m.stock : ''"></span> <span x-text="m ? m.unit : ''"></span> in stock.
                    It comes out of <span x-text="m && m.active_code ? m.active_code : (m && m.next_code ? m.next_code : 'the next pack')"></span>.
                </p>
            </div>
            <div>
                <label class="{{ $label }}">How much (<span x-text="m ? m.unit : ''"></span>)</label>
                <input type="number" step="0.001" min="0.001" name="quantity" x-model="qty" required class="{{ $input }}">
                <p x-show="tooMuch()" style="display:none;" class="text-xs text-amber-600 mt-1">That is more than what is in stock.</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="{{ $label }}">What for (optional)</label>
                    <input type="text" name="note" maxlength="255" placeholder="e.g. jersey printing" class="{{ $input }}">
                </div>
                <div>
                    <label class="{{ $label }}">Invoice no. (optional)</label>
                    <input type="text" name="invoice" maxlength="30" placeholder="INV-..." class="{{ $input }}">
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">Save pull</button>
            </div>
        </form>

        {{-- Sheet materials (sintra board): a job cuts its size from the open sheet, or takes a whole sheet --}}
        <form x-show="modal === 'cut'" style="display:none;" :action="m ? m.urls.cut : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">Cut for a job</p>
                <p class="{{ $sub }}">
                    <span x-text="m ? m.name : ''"></span>: <span x-text="m ? m.stock : ''"></span> sq ft in stock.
                    <span x-show="m && m.active_code">Open sheet <span x-text="m ? m.active_code : ''"></span> has <span x-text="m ? m.active_left : ''"></span> of <span x-text="m && m.sheet ? m.sheet.area : ''"></span> sq ft left.</span>
                </p>
            </div>

            <div class="grid grid-cols-2 gap-2">
                <label class="rounded-lg border px-3 py-2 cursor-pointer"
                       :class="mode === 'size' ? 'border-brand-500 bg-brand-50 dark:bg-brand-600/20' : 'border-slate-300 dark:border-slate-600'">
                    <input type="radio" name="mode" value="size" x-model="mode" class="sr-only">
                    <span class="block text-sm font-medium text-ink dark:text-white">Cut a size</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">Only the job's size comes off the open sheet. The rest stays for the next jobs.</span>
                </label>
                <label class="rounded-lg border px-3 py-2 cursor-pointer"
                       :class="mode === 'whole' ? 'border-brand-500 bg-brand-50 dark:bg-brand-600/20' : 'border-slate-300 dark:border-slate-600'">
                    <input type="radio" name="mode" value="whole" x-model="mode" class="sr-only">
                    <span class="block text-sm font-medium text-ink dark:text-white">Whole sheet</span>
                    <span class="block text-xs text-slate-500 dark:text-slate-400">The job takes a full sheet.</span>
                </label>
            </div>

            <template x-if="mode === 'size'">
                <div class="space-y-2">
                    <div class="flex items-end gap-2">
                        <div class="flex-1 min-w-0">
                            <label class="{{ $label }}">Width</label>
                            <input type="number" step="0.01" min="0.01" name="width" x-model="w" required class="{{ $input }}">
                        </div>
                        <span class="pb-2 text-slate-400">x</span>
                        <div class="flex-1 min-w-0">
                            <label class="{{ $label }}">Height</label>
                            <input type="number" step="0.01" min="0.01" name="height" x-model="h" required class="{{ $input }}">
                        </div>
                        <div style="width:4.5rem;">
                            <label class="{{ $label }}">Unit</label>
                            <select name="size_unit" x-model="sizeUnit" class="{{ $input }}">
                                <option value="ft">ft</option>
                                <option value="in">in</option>
                            </select>
                        </div>
                        <div style="width:4.5rem;">
                            <label class="{{ $label }}">Pieces</label>
                            <input type="number" step="1" min="1" name="pieces" x-model="pieces" required class="{{ $input }}">
                        </div>
                    </div>
                    <p x-show="cutArea() > 0" class="text-sm text-ink dark:text-white">Takes <strong x-text="fmt(cutArea())"></strong> sq ft.</p>
                    <p x-show="cutTooBig()" class="text-xs text-amber-600">
                        That piece doesn't fit on one <span x-text="m && m.sheet ? m.sheet.label : ''"></span> sheet.<span x-show="sizeUnit === 'ft'"> Was it in inches?</span>
                    </p>
                    <p x-show="!cutTooBig() && m && m.active_code && cutArea() > m.active_left + 0.0005" class="text-xs text-amber-600">
                        Only <span x-text="m ? m.active_left : ''"></span> sq ft left on <span x-text="m ? m.active_code : ''"></span>:
                        the rest of it goes to this job, and the next sheet opens for the remainder.
                    </p>
                </div>
            </template>

            <template x-if="mode === 'whole'">
                <div class="space-y-2 text-sm text-ink dark:text-white">
                    {{-- The open sheet: all of it if untouched, else what's left of it --}}
                    <template x-if="m && m.active_code">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="radio" name="which" value="rest" x-model="which" required class="mt-1">
                            <span x-show="m.active_whole">The open sheet, <span x-text="m.active_code"></span> (<span x-text="m.active_left"></span> sq ft).</span>
                            <span x-show="!m.active_whole">The rest of <span x-text="m.active_code"></span> (<span x-text="m.active_left"></span> sq ft). It closes after this job.</span>
                        </label>
                    </template>
                    {{-- A sealed sheet, taken whole. Not offered when the open sheet is still whole: that one goes first. --}}
                    <template x-if="m && m.next_code && !(m.active_code && m.active_whole)">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="radio" name="which" value="new" x-model="which" required class="mt-1">
                            <span>
                                A new sheet, <span x-text="m.next_code"></span> (<span x-text="m.sheet ? m.sheet.area : ''"></span> sq ft).
                                <span x-show="m.active_code" class="text-slate-500 dark:text-slate-400">The open sheet stays for smaller cuts.</span>
                            </span>
                        </label>
                    </template>
                    <p x-show="m && !m.active_code && !m.next_code" class="text-xs text-amber-600">No sheet in the store. Restock, or move a sheet from the warehouse.</p>
                </div>
            </template>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="{{ $label }}">What for (optional)</label>
                    <input type="text" name="note" maxlength="255" placeholder="e.g. 2x3 signage" class="{{ $input }}">
                </div>
                <div>
                    <label class="{{ $label }}">Invoice no. (optional)</label>
                    <input type="text" name="invoice" maxlength="30" placeholder="INV-..." class="{{ $input }}">
                </div>
            </div>
            <p class="text-xs text-slate-400">Came out wrong? Log the good piece here, and the spoiled one with Mistake.</p>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }} disabled:opacity-40"
                        :disabled="mode === 'whole' && !(m && (m.active_code || m.next_code))">Save cut</button>
            </div>
        </form>
        @endif

        {{-- Open the next sealed pack; the active one closes --}}
        <form x-show="modal === 'open'" style="display:none;" :action="m ? m.urls.open : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}" x-text="m && m.sheet ? 'Open next sheet' : 'Open next pack'"></p>
                <p class="{{ $sub }}"><span x-text="m ? m.next_code : ''"></span> becomes the active batch of <span x-text="m ? m.name : ''"></span>.</p>
            </div>
            <div x-show="m && m.active_code" style="display:none;" class="rounded-lg bg-amber-100 dark:bg-amber-900/30 border border-amber-400 text-amber-700 dark:text-amber-400 px-3 py-2 text-sm">
                <span x-text="m ? m.active_code : ''"></span> will be closed.
                <span x-show="m && m.active_left > 0">It still has <span x-text="m ? m.active_left : ''"></span> <span x-text="m ? m.unit : ''"></span>, so say why it is written off.</span>
            </div>
            <div x-show="m && m.active_left > 0" style="display:none;">
                <label class="{{ $label }}">Why is the rest written off?</label>
                <input type="text" name="write_off_reason" maxlength="255" :placeholder="m && m.sheet ? 'e.g. leftover strip too small to use' : 'e.g. end of roll too short to use'" :required="!!(m && m.active_left > 0)" class="{{ $input }}">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}" x-text="m && m.sheet ? 'Open sheet' : 'Open pack'"></button>
            </div>
        </form>

        {{-- Move the oldest warehouse pack to the store --}}
        <form x-show="modal === 'transfer'" style="display:none;" :action="m ? m.urls.transfer : ''" method="POST" class="space-y-3">
            @csrf
            <p class="{{ $head }}" x-text="m && m.sheet ? 'Move a sheet to the store?' : 'Move a pack to the store?'"></p>
            <p class="{{ $sub }}">The oldest sealed <span x-text="m && m.sheet ? 'sheet' : 'pack'"></span> of <span x-text="m ? m.name : ''"></span> in the warehouse moves to the store, so it can be opened and used.</p>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">Move it</button>
            </div>
        </form>

        {{-- Damaged on the active batch --}}
        <form x-show="modal === 'loss'" style="display:none;" :action="m ? m.urls.loss : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">{{ $isCont ? 'Damaged' : 'Damaged pieces' }}</p>
                <p class="{{ $sub }}"><span x-text="m ? m.active_code : ''"></span> has <span x-text="m ? m.active_left : ''"></span> <span x-text="m ? m.unit : ''"></span> left.</p>
            </div>
            <div>
                <label class="{{ $label }}">How much (<span x-text="m ? m.unit : ''"></span>)</label>
                <input type="number" step="0.001" min="0.001" name="quantity" x-model="qty" required class="{{ $input }}">
            </div>
            <div>
                <label class="{{ $label }}">Reason</label>
                <input type="text" name="reason" required maxlength="255" placeholder="{{ $isCont ? 'e.g. water damage' : 'e.g. cracked in delivery' }}" class="{{ $input }}">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">Save</button>
            </div>
        </form>

        {{-- Physical count of the active batch --}}
        <form x-show="modal === 'count'" style="display:none;" :action="m ? m.urls.count : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">Count the active batch</p>
                <p class="{{ $sub }}">The system says <span x-text="m ? m.active_code : ''"></span> has <span x-text="m ? m.active_left : ''"></span> <span x-text="m ? m.unit : ''"></span>.</p>
            </div>
            <div>
                <label class="{{ $label }}">Counted (<span x-text="m ? m.unit : ''"></span>)</label>
                <input type="number" step="0.001" min="0" name="counted" x-model="qty" required class="{{ $input }}">
            </div>
            <div>
                <label class="{{ $label }}">Note (optional)</label>
                <input type="text" name="reason" maxlength="255" class="{{ $input }}">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">Save count</button>
            </div>
        </form>

        {{-- Archive: the material leaves this page; Restore on the archive page brings it back --}}
        <form x-show="modal === 'archive'" style="display:none;" :action="m ? m.urls.archive : ''" method="POST" class="space-y-3">
            @csrf
            @method('DELETE')
            <div>
                <p class="{{ $head }}">Archive <span x-text="m ? m.name : ''"></span>?</p>
                <p class="{{ $sub }}">It leaves this page and goes to the archive. Its batches and history are kept, and you can restore it any time.</p>
            </div>
            <div x-show="m && (m.stock > 0{{ $isCont ? '' : ' || m.used_by > 0' }})" style="display:none;" class="rounded-lg bg-amber-100 dark:bg-amber-900/30 border border-amber-400 text-amber-700 dark:text-amber-400 px-3 py-2 text-sm space-y-1">
                <p x-show="m && m.stock > 0">It still has <span x-text="m ? m.stock : ''"></span> <span x-text="m ? m.unit : ''"></span> in stock, which can't be used while it's archived.</p>
                @unless($isCont)
                    {{-- Discrete materials are deducted by production runs and sales; an archived one blocks them. --}}
                    <p x-show="m && m.used_by > 0"><span x-text="m ? m.used_by : ''"></span> <span x-text="m && m.used_by === 1 ? 'product size uses' : 'product sizes use'"></span> it, so making them will be refused until you restore it.</p>
                @endunless
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">Archive</button>
            </div>
        </form>

    </div>
</div>

<script>
const stockData = @js($payloads);

function stockPage() {
    return {
        m: null,
        modal: null,
        qty: '',
        sheet: false,       // Add: cut from whole sheets
        mode: 'size',       // Cut for a job: 'size' or 'whole'
        which: '',          // Whole sheet: 'rest' of the open sheet, or a 'new' one
        w: '',
        h: '',
        sizeUnit: 'ft',
        pieces: 1,

        show(modal, id = null) {
            this.m = id ? stockData[id] : null;
            this.modal = modal;
            this.qty = '';
            this.sheet = false;
            if (modal === 'cut') {
                this.mode = 'size';
                this.w = '';
                this.h = '';
                this.pieces = 1;
                // Only one choice? Pick it. Both (a part-used open sheet, or a new one)? Staff pick.
                this.which = this.m.active_code ? (this.m.active_whole || !this.m.next_code ? 'rest' : '') : 'new';
            }
        },

        hide() { this.modal = null; },

        tooMuch() {
            return !!this.m && parseFloat(this.qty) > this.m.stock;
        },

        // Width and height in feet.
        cutFeet() {
            const perFoot = this.sizeUnit === 'in' ? 12 : 1;
            return [parseFloat(this.w) / perFoot, parseFloat(this.h) / perFoot];
        },

        // Sq ft the cut takes: width x height x pieces.
        cutArea() {
            const [w, h] = this.cutFeet();
            const area = w * h * (parseInt(this.pieces) || 0);
            return area > 0 ? Math.round(area * 1000) / 1000 : 0;
        },

        cutTooBig() {
            const [w, h] = this.cutFeet();
            if (!this.m || !this.m.sheet || !(w > 0 && h > 0)) return false;
            const s = this.m.sheet;
            return Math.max(w, h) > Math.max(s.w, s.h) + 0.0005 || Math.min(w, h) > Math.min(s.w, s.h) + 0.0005;
        },

        fmt(n) { return (Math.round(n * 1000) / 1000).toLocaleString(); },
    };
}
</script>
