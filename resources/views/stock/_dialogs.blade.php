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
                <input type="text" name="name" required maxlength="150" placeholder="{{ $isCont ? 'e.g. Sublimation Fabric Roll' : 'e.g. PVC Card Blank' }}" class="{{ $input }}">
            </div>
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
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">Save</button>
            </div>
        </form>

        {{-- Restock without a purchase order: becomes a new sealed batch --}}
        <form x-show="modal === 'restock'" style="display:none;" :action="m ? m.urls.restock : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">Restock</p>
                <p class="{{ $sub }}"><span x-text="m ? m.name : ''"></span>. The new stock becomes its own batch with a batch number.</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="{{ $label }}">How much (<span x-text="m ? m.unit : ''"></span>)</label>
                    <input type="number" step="0.001" min="0.001" name="quantity" x-model="qty" required class="{{ $input }}">
                </div>
                <div>
                    <label class="{{ $label }}">Put it in</label>
                    <select name="location" class="{{ $input }}">
                        <option value="store">Store</option>
                        <option value="warehouse">Warehouse</option>
                    </select>
                </div>
            </div>
            <div>
                <label class="{{ $label }}">Cost per unit (optional)</label>
                <input type="number" step="0.01" min="0" name="cost_per_unit" class="{{ $input }}">
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
        @endif

        {{-- Open the next sealed pack; the active one closes --}}
        <form x-show="modal === 'open'" style="display:none;" :action="m ? m.urls.open : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">Open next pack</p>
                <p class="{{ $sub }}"><span x-text="m ? m.next_code : ''"></span> becomes the active batch of <span x-text="m ? m.name : ''"></span>.</p>
            </div>
            <div x-show="m && m.active_code" style="display:none;" class="rounded-lg bg-amber-100 dark:bg-amber-900/30 border border-amber-400 text-amber-700 dark:text-amber-400 px-3 py-2 text-sm">
                <span x-text="m ? m.active_code : ''"></span> will be closed.
                <span x-show="m && m.active_left > 0">It still has <span x-text="m ? m.active_left : ''"></span> <span x-text="m ? m.unit : ''"></span>, so say why it is written off.</span>
            </div>
            <div x-show="m && m.active_left > 0" style="display:none;">
                <label class="{{ $label }}">Why is the rest written off?</label>
                <input type="text" name="write_off_reason" maxlength="255" placeholder="e.g. end of roll too short to use" :required="!!(m && m.active_left > 0)" class="{{ $input }}">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }}">Open pack</button>
            </div>
        </form>

        {{-- Move the oldest warehouse pack to the store --}}
        <form x-show="modal === 'transfer'" style="display:none;" :action="m ? m.urls.transfer : ''" method="POST" class="space-y-3">
            @csrf
            <p class="{{ $head }}">Move a pack to the store?</p>
            <p class="{{ $sub }}">The oldest sealed pack of <span x-text="m ? m.name : ''"></span> in the warehouse moves to the store, so it can be opened and used.</p>
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

    </div>
</div>

<script>
const stockData = @js($payloads);

function stockPage() {
    return {
        m: null,
        modal: null,
        qty: '',

        show(modal, id = null) {
            this.m = id ? stockData[id] : null;
            this.modal = modal;
            this.qty = '';
        },

        hide() { this.modal = null; },

        tooMuch() {
            return !!this.m && parseFloat(this.qty) > this.m.stock;
        },
    };
}
</script>
