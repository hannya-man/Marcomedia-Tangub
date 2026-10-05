{{--
    Dialogs for the Raw Materials and Materials pages. $kind is 'raw' or 'material'.
    One overlay holds five forms; only the one matching `modal` shows. Each posts to its own route.
--}}
@php
    $isRaw = $kind === 'raw';
    $input = 'mt-1 w-full text-sm rounded-lg border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-700 dark:text-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500';
    $label = 'text-xs font-medium text-slate-600 dark:text-slate-300';
    $btnCancel = 'border border-slate-300 dark:border-slate-600 bg-white dark:bg-slate-800 hover:bg-slate-50 dark:hover:bg-slate-700 text-slate-700 dark:text-slate-200 text-sm font-medium rounded-md px-4 py-2';
    $btnPrimary = 'bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-md px-4 py-2';
@endphp

<div x-show="modal !== null" style="display:none;" x-transition.opacity
     class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4 overflow-y-auto"
     @click.self="hide()" @keydown.escape.window="hide()">
    <div class="bg-white dark:bg-slate-800 rounded-lg shadow-lg border border-slate-200 dark:border-slate-700 w-full max-w-md p-6 my-8">

        {{-- Add a new raw material or material --}}
        <form x-show="modal === 'add'" style="display:none;" method="POST" action="{{ route('stock.store') }}" class="space-y-3">
            @csrf
            <input type="hidden" name="inventory_type" value="{{ $kind }}">
            <div>
                <p class="text-lg font-semibold text-ink dark:text-white">{{ $isRaw ? 'Add raw material' : 'Add material' }}</p>
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    {{ $isRaw ? 'Whole units, like rolls. Removed by hand when used up.' : 'Pieces you can count, like a pack of 1,000.' }}
                </p>
            </div>
            <div>
                <label class="{{ $label }}">Name</label>
                <input type="text" name="name" required maxlength="150" placeholder="{{ $isRaw ? 'e.g. Sublimation Fabric Roll' : 'e.g. PVC Card Blank' }}" class="{{ $input }}">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="{{ $label }}">Unit</label>
                    <input type="text" name="unit" required maxlength="20" placeholder="{{ $isRaw ? 'rolls' : 'pcs' }}" class="{{ $input }}">
                </div>
                <div>
                    <label class="{{ $label }}">{{ $isRaw ? 'In stock now' : 'On hand now' }}</label>
                    <input type="number" step="0.01" min="0" name="stock_quantity" required value="0" class="{{ $input }}">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="{{ $label }}">{{ $isRaw ? 'Warn at (units in stock)' : 'Warn at (left in pack)' }}</label>
                    <input type="number" step="0.01" min="0" name="low_stock_threshold" placeholder="{{ $isRaw ? '1' : '50' }}" class="{{ $input }}">
                </div>
                <div>
                    <label class="{{ $label }}">{{ $isRaw ? 'Cost per unit (optional)' : 'Cost per piece (optional)' }}</label>
                    <input type="number" step="0.01" min="0" name="cost_per_unit" class="{{ $input }}">
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">Save</button>
            </div>
        </form>

        {{-- Open the next pack or unit --}}
        <form x-show="modal === 'open'" style="display:none;" :action="m ? m.urls.open : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                @if($isRaw)
                    <p class="text-lg font-semibold text-ink dark:text-white" x-text="m && m.new ? 'Start using one' : 'Use the next one'"></p>
                @else
                    <p class="text-lg font-semibold text-ink dark:text-white" x-text="m && m.new ? 'Open the first pack' : 'Open a new pack'"></p>
                @endif
                <p class="text-sm text-slate-500 dark:text-slate-400" x-text="m ? m.name : ''"></p>
            </div>

            <div x-show="m && m.cur_code" style="display:none;"
                 class="rounded-lg bg-amber-100 dark:bg-amber-900/30 border border-amber-400 text-amber-700 dark:text-amber-400 px-3 py-2 text-sm">
                @if($isRaw)
                    <span x-text="m ? m.cur_code : ''"></span> will be marked used up and moved to Used Materials.
                @else
                    <span x-text="m ? m.cur_code : ''"></span> will be closed.
                    <span x-show="m && m.cur_left > 0">The <span x-text="m ? m.cur_left : ''"></span> <span x-text="m ? m.unit : ''"></span> still in it will be written off.</span>
                @endif
            </div>

            @if($isRaw)
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    Takes 1 out of stock: <span x-text="m ? m.stock : ''"></span> <span x-text="m ? m.unit : ''"></span> now,
                    <span x-text="m ? Math.max(m.stock - 1, 0) : ''"></span> after.
                </p>
            @endif

            <div>
                <label class="{{ $label }}">Batch label</label>
                <input type="text" name="batch" x-model="batch" :list="m ? 'batches-' + m.id : null" required maxlength="60" class="{{ $input }}">
                <p class="text-xs text-slate-400 mt-1" x-text="'IDs look like ' + (batch || 'September 2026 CB') + ' - 1, then - 2.'"></p>
            </div>
            @unless($isRaw)
                <div>
                    <label class="{{ $label }}">How many are in the pack? (<span x-text="m ? m.unit : ''"></span>)</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" x-model="qty" required class="{{ $input }}">
                </div>
            @endunless
            <div>
                <label class="{{ $label }}">Supplier (optional)</label>
                <input type="text" name="supplier" x-model="supplier" maxlength="100" class="{{ $input }}">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">{{ $isRaw ? 'Start using it' : 'Open pack' }}</button>
            </div>
        </form>

        {{-- Raw Materials: add or remove stock by hand. Materials: fix the count of the open pack. --}}
        <form x-show="modal === 'adjust'" style="display:none;" :action="m ? m.urls.adjust : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="text-lg font-semibold text-ink dark:text-white">{{ $isRaw ? 'Add or remove stock' : 'Adjust the count' }}</p>
                @if($isRaw)
                    <p class="text-sm text-slate-500 dark:text-slate-400"><span x-text="m ? m.stock : ''"></span> <span x-text="m ? m.unit : ''"></span> in stock now.</p>
                @else
                    <p class="text-sm text-slate-500 dark:text-slate-400"><span x-text="m ? m.cur_code : ''"></span> has <span x-text="m ? m.cur_left : ''"></span> <span x-text="m ? m.unit : ''"></span> left.</p>
                @endif
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="{{ $label }}">Direction</label>
                    <select name="direction" x-model="dir" @change="syncReason()" class="{{ $input }}">
                        <option value="out">{{ $isRaw ? 'Remove' : 'Take out' }}</option>
                        <option value="in">{{ $isRaw ? 'Add' : 'Add back' }}</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}">How many (<span x-text="m ? m.unit : ''"></span>)</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" x-model="qty" required class="{{ $input }}">
                </div>
            </div>
            <div>
                <label class="{{ $label }}">Reason</label>
                <select name="reason" x-model="reason" class="{{ $input }}">
                    @if($isRaw)
                        <option value="used">Used (removed by hand)</option>
                        <option value="received">Received from supplier</option>
                    @endif
                    <option value="broken">Broken or damaged</option>
                    <option value="miscount">Miscount</option>
                    <option value="other">Other</option>
                </select>
            </div>
            <div>
                <label class="{{ $label }}">Note (optional)</label>
                <input type="text" name="note" x-model="note" maxlength="255" class="{{ $input }}">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">Save</button>
            </div>
        </form>

        {{-- Log a use or an error against the pack or unit in use --}}
        <form x-show="modal === 'usage'" style="display:none;" :action="m ? m.urls.usage : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="text-lg font-semibold text-ink dark:text-white">Log use or error</p>
                @if($isRaw)
                    <p class="text-sm text-slate-500 dark:text-slate-400">Tied to <span x-text="m ? m.cur_code : ''"></span>, the one in use. Nothing is subtracted.</p>
                @else
                    <p class="text-sm text-slate-500 dark:text-slate-400">Comes out of <span x-text="m ? m.cur_code : ''"></span>, which has <span x-text="m ? m.cur_left : ''"></span> <span x-text="m ? m.unit : ''"></span> left.</p>
                @endif
            </div>
            <div>
                <label class="{{ $label }}">What happened</label>
                <select name="use_type" x-model="useType" class="{{ $input }}">
                    <option value="job">Used for a job</option>
                    <option value="error">Error (rejected: counts as used, not as a sale amount)</option>
                </select>
            </div>
            @unless($isRaw)
                <div>
                    <label class="{{ $label }}">How many (<span x-text="m ? m.unit : ''"></span>)</label>
                    <input type="number" step="0.01" min="0.01" name="quantity" x-model="qty" required class="{{ $input }}">
                    <p x-show="tooMuch()" style="display:none;" class="text-xs text-amber-600 mt-1">That is more than what is left in this pack.</p>
                </div>
            @endunless
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="{{ $label }}">Invoice no. (optional)</label>
                    <input type="text" name="invoice" x-model="invoice" maxlength="30" placeholder="INV-..." class="{{ $input }}">
                </div>
                <div>
                    <label class="{{ $label }}">Note (optional)</label>
                    <input type="text" name="note" x-model="note" maxlength="255" class="{{ $input }}">
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">Save</button>
            </div>
        </form>

        {{-- Close the pack, or mark the raw unit used up --}}
        <form x-show="modal === 'close'" style="display:none;" :action="m ? m.urls.close : ''" method="POST" class="space-y-3">
            @csrf
            <p class="text-lg font-semibold text-ink dark:text-white">{{ $isRaw ? 'Mark it used up?' : 'Close this pack?' }}</p>
            @if($isRaw)
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    <span class="font-medium text-ink dark:text-white" x-text="m ? m.cur_code : ''"></span> moves to Used Materials.
                    Sales that need <span x-text="m ? m.name : ''"></span> are blocked until you start the next one.
                </p>
            @else
                <p class="text-sm text-slate-500 dark:text-slate-400">
                    <span class="font-medium text-ink dark:text-white" x-text="m ? m.cur_code : ''"></span> will be closed<span x-show="m && m.cur_left > 0">, and the <span x-text="m ? m.cur_left : ''"></span> <span x-text="m ? m.unit : ''"></span> still in it written off</span>.
                    Sales and uses that need <span x-text="m ? m.name : ''"></span> are blocked until you open a new pack.
                </p>
            @endif
            <div class="flex flex-col-reverse sm:flex-row sm:justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Keep it</button>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">{{ $isRaw ? 'Mark used up' : 'Close pack' }}</button>
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
        batch: '', qty: '', supplier: '',
        dir: 'out', reason: '', note: '',
        useType: 'job', invoice: '',

        show(modal, id = null) {
            const mat = id ? stockData[id] : null;
            this.m = mat;
            this.modal = modal;
            this.batch = mat ? mat.suggest : '';
            // A material's first pack starts from the count it already has.
            this.qty = (modal === 'open' && mat && mat.kind === 'material' && mat.new && mat.stock > 0) ? mat.stock : '';
            this.supplier = '';
            this.dir = 'out';
            this.reason = '{{ $isRaw ? 'used' : 'broken' }}';
            this.note = '';
            this.useType = 'job';
            this.invoice = '';
        },

        hide() { this.modal = null; },

        // Raw Materials: adding stock is usually a delivery, removing it is usually use.
        syncReason() {
            if ('{{ $kind }}' !== 'raw') return;
            if (this.dir === 'in' && this.reason === 'used') this.reason = 'received';
            if (this.dir === 'out' && this.reason === 'received') this.reason = 'used';
        },

        tooMuch() {
            return !!this.m && this.m.kind === 'material' && parseFloat(this.qty) > this.m.cur_left;
        },
    };
}
</script>
