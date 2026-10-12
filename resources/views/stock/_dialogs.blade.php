{{--
    Dialogs for the Continuous and Discrete pages. $kind is 'continuous' or 'discrete'.
    One overlay holds every form; only the one matching `modal` shows. Each posts to its own route.
--}}
@php
    $isCont = $kind === 'continuous';
    $input = 'mt-1 w-full text-sm rounded-lg border border-slate-300 bg-white px-3 py-2 focus:ring-2 focus:ring-brand-500 focus:border-brand-500';
    $label = 'text-xs font-medium text-slate-600';
    $btnCancel = 'border border-slate-300 bg-white hover:bg-slate-50 text-slate-700 text-sm font-medium rounded-md px-4 py-2';
    $btnPrimary = 'bg-brand-600 hover:bg-brand-700 text-white text-sm font-medium rounded-md px-4 py-2';
    $head = 'text-lg font-semibold text-ink';
    $sub = 'text-sm text-slate-500';
@endphp

<div x-show="modal !== null" style="display:none;" x-transition.opacity
     class="fixed inset-0 z-50 bg-black/50 flex items-center justify-center p-4 overflow-y-auto"
     @click.self="hide()" @keydown.escape.window="hide()">
    <div class="bg-white rounded-lg shadow-lg border border-slate-200 w-full max-w-md p-6 my-8">

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
                <input type="text" name="name" required maxlength="150" :placeholder="sheet ? '{{ $isCont ? 'e.g. Tarpaulin Roll 8 ft' : 'e.g. Sintra Board 5mm' }}' : '{{ $isCont ? 'e.g. Sublimation Fabric Roll' : 'e.g. PVC Card Blank' }}'" class="{{ $input }}">
            </div>
            {{-- Boards (Discrete) and rolls (Continuous) of a set size: counted whole, with fixed yields --}}
            <label class="flex items-start gap-2 rounded-lg border border-slate-200 px-3 py-2 cursor-pointer">
                <input type="checkbox" name="sheet" value="1" x-model="sheet" class="mt-0.5 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                <span>
                    @if($isCont)
                        <span class="block text-sm text-ink">Comes in rolls of a set size</span>
                        <span class="block text-xs text-slate-500">Like tarpaulin. Each roll is measured into one-foot blocks at full width, and a job takes whole feet.</span>
                    @else
                        <span class="block text-sm text-ink">Comes in whole boards of a set size</span>
                        <span class="block text-xs text-slate-500">Like sintra board or acrylic. A board is cut whole into one standard size, with a fixed yield, and jobs take the pieces.</span>
                    @endif
                </span>
            </label>
            <template x-if="sheet">
                <div class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="{{ $label }}">{{ $isCont ? 'Width x length (ft)' : 'Board size (ft)' }}</label>
                            <div class="flex items-center gap-2">
                                <input type="number" step="0.01" min="0.5" max="50" name="sheet_width" required placeholder="4" aria-label="{{ $isCont ? 'Roll width' : 'Board width' }} in feet" class="{{ $input }}">
                                <span class="mt-1 text-slate-400">x</span>
                                <input type="number" step="0.01" min="0.5" max="{{ $isCont ? 1000 : 50 }}" name="sheet_height" required placeholder="{{ $isCont ? 164 : 8 }}" value="{{ $isCont ? 164 : '' }}" aria-label="{{ $isCont ? 'Roll length' : 'Board height' }} in feet" class="{{ $input }}">
                            </div>
                        </div>
                        <div>
                            <label class="{{ $label }}">{{ $isCont ? 'Rolls' : 'Boards' }} on hand now (optional)</label>
                            <input type="number" step="1" min="0" max="500" name="sheets" class="{{ $input }}">
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="{{ $label }}">Reorder at ({{ $isCont ? 'rolls' : 'boards' }})</label>
                            <input type="number" step="0.5" min="0" max="500" name="reorder_sheets" placeholder="1" class="{{ $input }}">
                        </div>
                        <div>
                            <label class="{{ $label }}">Cost per {{ $isCont ? 'roll' : 'board' }} (optional)</label>
                            <input type="number" step="0.01" min="0" name="cost_per_sheet" class="{{ $input }}">
                        </div>
                    </div>
                    <p class="text-xs text-slate-400">
                        {{ $isCont ? 'A 50 m roll is 164 ft. Each roll on hand becomes its own batch.' : 'Each board on hand becomes its own batch. Then add the sizes you cut it into on its card.' }}
                    </p>
                </div>
            </template>
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

        {{-- Restock without a purchase order: becomes a new sealed batch. Boards and rolls: one batch each. --}}
        <form x-show="modal === 'restock'" style="display:none;" :action="m ? m.urls.restock : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">Restock</p>
                <p class="{{ $sub }}">
                    <span x-text="m ? m.name : ''"></span>.
                    <span x-text="m && m.sheet ? 'Each ' + m.sheet.noun + ' becomes its own batch, with a batch number.' : 'The new stock becomes its own batch with a batch number.'"></span>
                </p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <template x-if="m && m.sheet">
                    <div>
                        <label class="{{ $label }}">How many <span x-text="m && m.sheet ? m.sheet.noun + 's' : ''"></span></label>
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
                <label class="{{ $label }}" x-text="m && m.sheet ? 'Cost per ' + m.sheet.noun + ' (optional)' : 'Cost per unit (optional)'"></label>
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
        @endif

        {{-- Open the next sealed pack; the active one closes --}}
        <form x-show="modal === 'open'" style="display:none;" :action="m ? m.urls.open : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">Open next pack</p>
                <p class="{{ $sub }}"><span x-text="m ? m.next_code : ''"></span> becomes the active batch of <span x-text="m ? m.name : ''"></span>.</p>
            </div>
            <div x-show="m && m.active_code" style="display:none;" class="rounded-lg bg-amber-100 border border-amber-400 text-amber-700 px-3 py-2 text-sm">
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
            <p class="{{ $head }}" x-text="m && m.sheet ? 'Move a ' + m.sheet.noun + ' to the store?' : 'Move a pack to the store?'"></p>
            <p class="{{ $sub }}">The oldest sealed <span x-text="m && m.sheet ? m.sheet.noun : 'pack'"></span> of <span x-text="m ? m.name : ''"></span> in the warehouse moves to the store, so it can be opened and used.</p>
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

        {{-- Boards: cut whole ones into one standard size. The pieces go into stock; sales take them. --}}
        <form x-show="modal === 'cutboards'" style="display:none;" :action="cut() ? cut().url : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">Cut boards</p>
                <p class="{{ $sub }}">
                    <span x-text="m ? m.name : ''"></span>: <span x-text="m && m.sheet ? m.sheet.whole : 0"></span> whole in the store.
                    A board is cut whole into one size. Sales cut the next board themselves when a size runs out.
                </p>
            </div>
            <div>
                <label class="{{ $label }}">Size</label>
                <select x-model.number="cutId" class="{{ $input }}">
                    <template x-for="c in (m && m.sheet ? m.sheet.cuts : [])" :key="c.id">
                        <option :value="c.id" :selected="c.id === cutId" x-text="c.label + ': ' + c.yield + ' from a board'"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="{{ $label }}">How many boards</label>
                <input type="number" name="boards" step="1" min="1" max="100" x-model.number="boards" required class="{{ $input }}">
            </div>
            <p x-show="cut() && boards >= 1" class="text-sm text-ink">
                Makes <strong x-text="(cut() ? cut().yield : 0) * (boards || 0)"></strong> pieces of <span x-text="cut() ? cut().label : ''"></span>.
                <span class="text-slate-500"><span x-text="cut() ? cut().trim : 0"></span>% of each board is trim, paid for in the pieces' cost.</span>
            </p>
            <p x-show="m && m.sheet && boards > m.sheet.whole" style="display:none;" class="text-xs text-amber-600">
                Only <span x-text="m && m.sheet ? m.sheet.whole : 0"></span> whole in the store.
            </p>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }} disabled:opacity-40" :disabled="!cut() || !(boards >= 1) || !!(m && m.sheet && boards > m.sheet.whole)">Cut</button>
            </div>
        </form>

        {{-- A whole board or roll that can't be used: written off, never cut --}}
        <form x-show="modal === 'writeoff'" style="display:none;" :action="m ? m.urls.writeoff : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">Damaged <span x-text="m && m.sheet ? m.sheet.noun : ''"></span></p>
                <p class="{{ $sub }}">One whole <span x-text="m && m.sheet ? m.sheet.noun : ''"></span> of <span x-text="m ? m.name : ''"></span> is written off.</p>
            </div>
            <div>
                <label class="{{ $label }}">Reason</label>
                <input type="text" name="reason" required maxlength="255" placeholder="e.g. cracked in storage" class="{{ $input }}">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2">Write off</button>
            </div>
        </form>

        {{-- A new standard size for a board. Its yield comes from the real sizes. --}}
        <form x-show="modal === 'addsize'" style="display:none;" :action="m ? m.urls.addsize : ''" method="POST" class="space-y-3">
            @csrf
            <div>
                <p class="{{ $head }}">Add a size</p>
                <p class="{{ $sub }}">A size <span x-text="m ? m.name : ''"></span> is cut into. How many one <span x-text="m && m.sheet ? m.sheet.label : ''"></span> board gives is worked out from the sizes.</p>
            </div>
            <div>
                <label class="{{ $label }}">Name (optional)</label>
                <input type="text" name="label" maxlength="60" placeholder="e.g. Legal" class="{{ $input }}">
            </div>
            <div class="flex items-end gap-2">
                <div class="flex-1 min-w-0">
                    <label class="{{ $label }}">Width</label>
                    <input type="number" step="0.01" min="0.1" name="width" x-model="sw" required class="{{ $input }}">
                </div>
                <span class="pb-2 text-slate-400">x</span>
                <div class="flex-1 min-w-0">
                    <label class="{{ $label }}">Height</label>
                    <input type="number" step="0.01" min="0.1" name="height" x-model="sh" required class="{{ $input }}">
                </div>
                <div style="width:4.5rem;">
                    <label class="{{ $label }}">Unit</label>
                    <select name="unit" x-model="su" class="{{ $input }}">
                        <option value="in">in</option>
                        <option value="ft">ft</option>
                    </select>
                </div>
            </div>
            <p x-show="sizeYield() > 0" class="text-sm text-ink">One board gives <strong x-text="sizeYield()"></strong> pieces.</p>
            <p x-show="sw > 0 && sh > 0 && sizeYield() === 0" style="display:none;" class="text-xs text-amber-600">That doesn't fit on one board.<span x-show="su === 'ft'"> Was it in inches?</span></p>
            <p class="text-xs text-slate-400">The items cut from this board get it as a size too.</p>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="{{ $btnPrimary }} disabled:opacity-40" :disabled="sizeYield() === 0">Add size</button>
            </div>
        </form>

        {{-- Stop cutting a size: off the board and off the items cut from it --}}
        <form x-show="modal === 'removesize'" style="display:none;" :action="cut() ? cut().remove : ''" method="POST" class="space-y-3">
            @csrf
            @method('DELETE')
            <div>
                <p class="{{ $head }}">Stop cutting <span x-text="cut() ? cut().label : ''"></span>?</p>
                <p class="{{ $sub }}">It comes off <span x-text="m ? m.name : ''"></span> and off the items cut from it. Past sales keep it.</p>
            </div>
            <p x-show="cut() && cut().ready > 0" style="display:none;" class="rounded-lg bg-amber-100 border border-amber-400 text-amber-700 px-3 py-2 text-sm">
                <span x-text="cut() ? cut().ready : 0"></span> are still cut. Use or sell them first.
            </p>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" @click="hide()" class="{{ $btnCancel }}">Cancel</button>
                <button type="submit" class="bg-red-600 hover:bg-red-700 text-white text-sm font-medium rounded-md px-4 py-2 disabled:opacity-40" :disabled="!!(cut() && cut().ready > 0)">Remove size</button>
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
            <div x-show="m && (m.stock > 0{{ $isCont ? '' : ' || m.used_by > 0' }})" style="display:none;" class="rounded-lg bg-amber-100 border border-amber-400 text-amber-700 px-3 py-2 text-sm space-y-1">
                <p x-show="m && m.stock > 0">It still has <span x-text="m && m.sheet ? m.sheet.whole + ' whole ' + m.sheet.noun + (m.sheet.whole === 1 ? '' : 's') : (m ? m.stock + ' ' + m.unit : '')"></span> in stock, which can't be used while it's archived.</p>
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
        find: '',           // the search box over the cards
        qty: '',
        sheet: false,       // Add: comes in boards or rolls of a set size
        cutId: null,        // Cut boards / Remove a size: the board's size picked
        boards: 1,
        sw: '',             // Add a size: width, height, unit
        sh: '',
        su: 'in',

        // cutId: a board's size, when the dialog is opened from that size's row.
        show(modal, id = null, cutId = null) {
            this.m = id ? stockData[id] : null;
            this.modal = modal;
            this.qty = '';
            this.sheet = false;
            if (modal === 'cutboards' || modal === 'removesize') {
                const cuts = this.m && this.m.sheet ? this.m.sheet.cuts : [];
                this.cutId = cutId ?? (cuts.length ? cuts[0].id : null);
                this.boards = 1;
            }
            if (modal === 'addsize') {
                this.sw = '';
                this.sh = '';
                this.su = 'in';
            }
        },

        // The board's size picked in Cut boards or Remove a size.
        cut() {
            return this.m && this.m.sheet ? this.m.sheet.cuts.find(c => c.id === this.cutId) || null : null;
        },

        // Add a size: how many pieces one board gives, cut in a grid whichever way round fits more
        // (same rule as SheetYield::perSheet). 0 if it doesn't fit.
        sizeYield() {
            const per = this.su === 'ft' ? 12 : 1;
            const w = parseFloat(this.sw) * per, h = parseFloat(this.sh) * per;
            if (!this.m || !this.m.sheet || !(w > 0 && h > 0)) return 0;
            const W = this.m.sheet.w * 12, H = this.m.sheet.h * 12;
            const fit = (a, b) => Math.floor(a / b + 1e-9);
            return Math.max(fit(W, w) * fit(H, h), fit(W, h) * fit(H, w));
        },

        hide() { this.modal = null; },

        // Every word in the search box is in the material's name or code.
        shown(id) {
            const text = (stockData[id].name + ' ' + (stockData[id].code || '')).toLowerCase();
            return this.find.toLowerCase().split(/\s+/).filter(Boolean).every(w => text.includes(w));
        },
        noneShown() {
            return Object.keys(stockData).length > 0 && !Object.keys(stockData).some(id => this.shown(id));
        },

        tooMuch() {
            return !!this.m && parseFloat(this.qty) > this.m.stock;
        },
    };
}
</script>
