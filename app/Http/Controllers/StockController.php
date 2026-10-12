<?php
namespace App\Http\Controllers;

use App\Models\BatchAdjustment;
use App\Models\BatchConsumption;
use App\Models\InventoryBatch;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RejectedOutput;
use App\Models\Sale;
use App\Services\Inventory\BackorderService;
use App\Services\Inventory\BatchInventoryService;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\SheetYield;
use App\Services\Inventory\StockAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Three of the four inventory types. All stock lives in batches (inventory_batches),
 * and every change goes through BatchInventoryService.
 *   Continuous Raw Materials   - fabric rolls, thread, liquids. Staff log each pull for use.
 *                                Rolls of a set size (tarpaulin) are measured into one-foot blocks.
 *   Discrete Materials         - PVC cards, RFID chips, fasteners. Deducted 1 to 1 by sales.
 *                                Boards of a set size (sintra board) are cut whole into standard
 *                                pieces with fixed yields; sales take the pieces.
 *   Scrapped / Rejected Output - failed production runs, reprints, and scrap sold from them.
 * The fourth, Products, is the existing Stock page (InventoryController).
 * Restock goes to MaterialController::adjustStock, which makes a new batch without a PO.
 * Boards and rolls restock whole instead (restockSheets), one batch each.
 * Stock coming in (a restock, a pack moved to the store, a count up) goes first to items sold
 * while it was out, on order (BackorderService).
 */
class StockController extends Controller
{
    public function __construct(private BatchInventoryService $inventory, private BackorderService $backorders)
    {
    }

    public function continuous()
    {
        return view('stock.continuous', $this->board('continuous'));
    }

    public function discrete()
    {
        return view('stock.discrete', $this->board('discrete'));
    }

    public function rejected(Request $request)
    {
        $month = (string) $request->query('month', '');
        $start = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
            ? Carbon::createFromFormat('!Y-m', $month)->startOfMonth()
            : now()->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $rejects = RejectedOutput::with(['user', 'sale', 'scrapSale', 'losses.batch.material'])
            ->whereBetween('created_at', [$start, $end])
            ->orderByDesc('id')
            ->get();

        $totals = [
            'records' => $rejects->count(),
            'units' => (int) $rejects->sum('quantity'),
            'cost' => (float) $rejects->sum('material_cost'),
            'reprint_cost' => (float) $rejects->whereNotNull('sale_id')->sum('material_cost'),
            'scrap' => (float) $rejects->where('status', 'scrap_sold')->sum('scrap_revenue'),
        ];

        // Whole boards and rolls aren't wasted by a reject: their cut pieces are (fixed yields).
        $materials = Material::whereNull('archived_at')->whereNull('sheet_width')->orderBy('name')
            ->get(['id', 'name', 'unit', 'inventory_type']);

        // "Mistake" on a board's size opens the form with that size filled in.
        $recordFor = $materials->firstWhere('id', (int) $request->query('record'))?->id;

        // Product sizes with a recipe, so a rejected run can fill in its materials. A size cut from
        // a board or roll wastes the standard piece it's charged as, like a sale would take.
        $recipes = ProductVariant::with(['product:id,name', 'materials:id,inventory_type,sheet_width,sheet_height,sheet_kind'])
            ->whereHas('materials')
            ->get()
            ->map(function ($v) {
                $lines = $v->materials->reject->isSheet()->map(function ($m) {
                    return [
                        'material_id' => $m->id,
                        'per_unit' => (float) $m->pivot->quantity_per_unit,
                        'fixed' => ($m->pivot->consumption_type ?? 'fixed') === 'fixed',
                        'continuous' => $m->inventory_type === 'continuous',
                    ];
                })->values();
                $stocks = $v->materials->filter->isSheet();
                $size = $v->attributes ?? [];
                if ($stocks->isNotEmpty() && isset($size['width_in'], $size['height_in'])) {
                    $cut = $this->inventory->chooseCut($stocks, (float) $size['width_in'], (float) $size['height_in']);
                    if ($cut) {
                        $lines->push(['material_id' => $cut['piece']->id, 'per_unit' => (float) $cut['blocks'], 'fixed' => true, 'continuous' => false]);
                    }
                }

                return [
                    'id' => $v->id,
                    'label' => ($v->product->name ?? 'Product') . ' - ' . $v->variant_name,
                    'lines' => $lines,
                ];
            })
            ->filter(fn ($r) => $r['lines']->isNotEmpty())
            ->sortBy('label')
            ->values();

        return view('stock.rejected', compact('start', 'rejects', 'totals', 'materials', 'recipes', 'recordFor'));
    }

    // Add a material straight from its page. Stock already on hand becomes its first batch.
    public function store(Request $request)
    {
        if ($request->boolean('sheet')) {
            return $this->storeSheet($request);
        }

        $data = $request->validate([
            'inventory_type' => 'required|in:continuous,discrete',
            'name' => 'required|string|max:150',
            'unit' => 'required|string|max:20',
            'opening_stock' => 'nullable|numeric|min:0',
            'low_stock_threshold' => 'nullable|numeric|min:0',
            'cost_per_unit' => 'nullable|numeric|min:0',
        ]);

        try {
            $material = DB::transaction(function () use ($data) {
                $fields = array_filter(collect($data)->except('opening_stock')->all(), fn ($v) => $v !== null);
                $material = new Material();
                $material->forceFill($fields + ['stock_quantity' => 0])->save();
                $this->inventory->ensureCode($material);

                if ((float) ($data['opening_stock'] ?? 0) > 0) {
                    $this->inventory->recordOpeningStock($material, (float) $data['opening_stock'], 'store', Auth::id());
                } else {
                    $this->inventory->refreshMaterial($material);
                }

                return $material;
            });
        } catch (InventoryException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$material->name} added to " . $this->pageName($material->inventory_type) . '.');
    }

    // Put a material with no type yet under Continuous or Discrete.
    public function setType(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $data = $request->validate(['inventory_type' => 'required|in:continuous,discrete']);
        $material->forceFill(['inventory_type' => $data['inventory_type']])->save();

        return back()->with('success', "{$material->name} is now under " . $this->pageName($data['inventory_type']) . '.');
    }

    // Continuous only: staff log what they pulled for use.
    public function pull(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }
        if ($material->inventory_type !== 'continuous') {
            return back()->with('error', "{$material->name} is a discrete material. It is deducted automatically when sold.");
        }

        $data = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'note' => 'nullable|string|max:255',
            'invoice' => 'nullable|string|max:30',
        ]);

        return $this->attempt(function () use ($material, $data) {
            $sale = $this->invoice($data['invoice'] ?? null);
            $rows = $this->inventory->pullForUse($material, (float) $data['quantity'], $data['note'] ?? null, Auth::id(), $sale?->id);
            $batches = $rows->map(fn ($row) => $row->batch->batch_number)->unique()->implode(' and ');

            return 'Pulled ' . $this->fmt($data['quantity']) . " {$material->unit} of {$material->name} from {$batches}. "
                . $this->fmt($material->fresh()->stock_quantity) . " {$material->unit} left in stock.";
        });
    }

    // Boards and rolls: cut whole ones into pieces of one standard size (fixed yield). Sales also
    // cut the next board themselves when a size runs out; this is for cutting ahead.
    public function cutBoards(Request $request, Material $material)
    {
        $board = $material->cutFrom;
        if (! $board) {
            return back()->with('error', "{$material->name} is not a cut size of a board or roll.");
        }
        if ($blocked = $this->archived($board)) {
            return $blocked;
        }

        $data = $request->validate(['boards' => 'required|integer|min:1|max:100']);

        return $this->attempt(function () use ($material, $board, $data) {
            $made = $this->inventory->cutBoards($material, (int) $data['boards'], Auth::id());
            $n = $made->count();
            $numbers = $n > 1 ? $made->first()->batch_number . ' to ' . $made->last()->batch_number : $made->first()->batch_number;
            $what = $board->isRoll() ? ($n * $material->cut_yield) . ' ft' : ($n * $material->cut_yield) . ' x ' . $material->cutLabel();

            return ($board->isRoll() ? 'Measured ' : 'Cut ') . "{$n} " . $board->sheetNoun() . ($n === 1 ? '' : 's')
                . " of {$board->name} into {$what}: {$numbers}.";
        });
    }

    // A whole board or roll that can't be used (cracked, warped): written off, never cut.
    public function writeOffBoard(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $data = $request->validate(['reason' => 'required|string|max:255']);

        return $this->attempt(function () use ($material, $data) {
            $this->inventory->writeOffBoard($material, $data['reason'], Auth::id());

            return "Wrote off one {$material->sheetNoun()} of {$material->name}: {$data['reason']}.";
        });
    }

    // A new standard size for a board. Its yield comes from the real sizes, and the items cut
    // from the board get it as a size too.
    public function addCutSize(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }
        if (! $material->isSheet() || $material->isRoll()) {
            return back()->with('error', "Cut sizes are for boards. A roll is measured by the foot.");
        }

        $data = $request->validate([
            'label' => 'nullable|string|max:60',
            'width' => 'required|numeric|min:0.1|max:600',
            'height' => 'required|numeric|min:0.1|max:600',
            'unit' => 'required|in:in,ft',
        ]);
        $per = $data['unit'] === 'ft' ? 12 : 1;
        $w = round($data['width'] * $per, 2);
        $h = round($data['height'] * $per, 2);
        $size = $this->fmt($data['width']) . ' x ' . $this->fmt($data['height']) . " {$data['unit']}";
        $label = trim((string) ($data['label'] ?? '')) !== '' ? trim($data['label']) . " ({$size})" : $size;

        if (SheetYield::perSheet($material->sheet_width * 12, $material->sheet_height * 12, $w, $h) < 1) {
            return back()->withInput()->with('error', "A {$size} piece doesn't fit on one {$material->sheetLabel()} board.");
        }
        $same = $material->cutSizes()->whereNull('archived_at')->get()->first(function ($p) use ($w, $h) {
            return SheetYield::fits($w, $h, (float) $p->cut_width_in, (float) $p->cut_height_in)
                && SheetYield::fits((float) $p->cut_width_in, (float) $p->cut_height_in, $w, $h);
        });
        if ($same) {
            return back()->with('error', "{$material->name} already has that size: {$same->cutLabel()}.");
        }

        $piece = DB::transaction(function () use ($material, $label, $w, $h) {
            $piece = $this->makePiece($material, $label, $w, $h);
            $this->addSizeToItems($material, $label, $w, $h);
            return $piece;
        });

        return back()->with('success', "Added {$piece->cutLabel()} to {$material->name}: {$piece->cut_yield} from one board.");
    }

    // A size the shop stops cutting. Pieces already cut must be used first; past sales keep it.
    public function removeCutSize(Material $material)
    {
        $board = $material->cutFrom;
        if (! $board) {
            return back()->with('error', "{$material->name} is not a cut size of a board.");
        }
        if ((float) $material->stock_quantity > 0) {
            return back()->with('error', "{$material->cutLabel()} still has " . $this->fmt($material->stock_quantity)
                . " {$material->unit} cut. Use or sell them first.");
        }

        DB::transaction(function () use ($material, $board) {
            $material->forceFill(['archived_at' => now()])->save();
            // Items cut from the board stop offering it.
            ProductVariant::whereIn('product_id', $this->itemsCutFrom($board))->where('status', 'active')->get()
                ->filter(function ($v) use ($material) {
                    return (float) ($v->attributes['width_in'] ?? 0) === (float) $material->cut_width_in
                        && (float) ($v->attributes['height_in'] ?? 0) === (float) $material->cut_height_in;
                })
                ->each(function ($v) { $v->forceFill(['status' => 'inactive'])->save(); });
        });

        return back()->with('success', "{$board->name} no longer has the {$material->cutLabel()} size.");
    }

    // Boards and rolls restock whole: each one becomes its own sealed batch.
    public function restockSheets(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $data = $request->validate([
            'sheets' => 'required|integer|min:1|max:500',
            'location' => 'required|in:store,warehouse',
            'cost_per_sheet' => 'nullable|numeric|min:0',
        ]);

        return $this->attempt(function () use ($material, $data) {
            $batches = $this->inventory->restockSheets($material, (int) $data['sheets'], $data['location'], Auth::id(),
                isset($data['cost_per_sheet']) ? (float) $data['cost_per_sheet'] : null);
            $numbers = $batches->count() > 1
                ? $batches->first()->batch_number . ' to ' . $batches->last()->batch_number
                : $batches->first()->batch_number;

            return "Restocked {$batches->count()} " . $material->sheetNoun() . ($batches->count() === 1 ? '' : 's') . " of {$material->name}: {$numbers}."
                . $this->backorders->fillAfterRestock(Auth::id());
        });
    }

    // Sir Jay's rule: open the next sealed pack in the store; the active one closes.
    public function openNext(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $data = $request->validate(['write_off_reason' => 'nullable|string|max:255']);

        $next = InventoryBatch::where('material_id', $material->id)->where('status', 'unopened')
            ->where('location', 'store')->orderBy('received_at')->orderBy('id')->first();
        if (! $next) {
            return back()->with('error', "No sealed pack of {$material->name} in the store. Restock it, or move a pack from the warehouse.");
        }

        $leftover = filled($data['write_off_reason'] ?? null) ? ['write_off_reason' => $data['write_off_reason']] : [];

        return $this->attempt(function () use ($next, $leftover) {
            $this->inventory->openBatch($next, Auth::id(), $leftover);

            return "{$next->batch_number} is now the active batch.";
        });
    }

    public function transfer(Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $pack = InventoryBatch::where('material_id', $material->id)->where('status', 'unopened')
            ->where('location', 'warehouse')->orderBy('received_at')->orderBy('id')->first();
        if (! $pack) {
            return back()->with('error', "No sealed pack of {$material->name} in the warehouse.");
        }

        return $this->attempt(function () use ($pack) {
            $this->inventory->transferToStore($pack);

            return "{$pack->batch_number} moved to the store." . $this->backorders->fillAfterRestock(Auth::id());
        });
    }

    // Broken or damaged pieces on the active batch, e.g. 5 of 500 cracked.
    public function loss(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $data = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'reason' => 'required|string|max:255',
        ]);

        $active = $material->activeBatch()->first();
        if (! $active) {
            return back()->with('error', "{$material->name} has no open batch. Open the next pack first.");
        }

        return $this->attempt(function () use ($active, $data, $material) {
            $this->inventory->recordLoss($active, 'damaged', (float) $data['quantity'], $data['reason'], Auth::id());

            return 'Recorded ' . $this->fmt($data['quantity']) . " {$material->unit} damaged on {$active->batch_number}.";
        });
    }

    // Physical count of the active batch.
    public function count(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $data = $request->validate([
            'counted' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:255',
        ]);

        $active = $material->activeBatch()->first();
        if (! $active) {
            return back()->with('error', "{$material->name} has no open batch to count.");
        }

        return $this->attempt(function () use ($active, $data, $material) {
            $adjustment = $this->inventory->recordCount($active, (float) $data['counted'], Auth::id(), $data['reason'] ?? null);

            if (! $adjustment) {
                return 'The count matches the system. Nothing changed.';
            }

            return "Count saved on {$active->batch_number}: " . ($adjustment->quantity > 0 ? '+' : '') . $this->fmt($adjustment->quantity) . " {$material->unit}."
                . ($adjustment->quantity > 0 ? $this->backorders->fillAfterRestock(Auth::id()) : '');
        });
    }

    public function storeRejected(Request $request)
    {
        $data = $request->validate([
            'item_name' => 'required|string|max:150',
            'quantity' => 'required|integer|min:1|max:100000',
            'cause' => 'required|in:machine,process,other',
            'reason' => 'required|string|max:255',
            'product_variant_id' => 'nullable|exists:product_variants,id',
            'invoice' => 'nullable|string|max:30',
            'lines' => 'required|array|min:1|max:20',
            'lines.*.material_id' => 'nullable|exists:materials,id',
            'lines.*.quantity' => 'nullable|numeric|min:0',
            // Filled in only when the rejects were still sold.
            'scrap_revenue' => 'nullable|numeric|min:0.01|max:9999999',
            'payment_method' => 'required_with:scrap_revenue|in:cash,gcash',
        ]);

        return $this->attempt(function () use ($data) {
            // The customer's job it was reprinted for: that sale still counts, at less profit.
            $sale = $this->invoice($data['invoice'] ?? null);

            // Recorded, and sold if it was, in one step: all or nothing.
            [$rejected, $scrapSale] = DB::transaction(function () use ($data, $sale) {
                $rejected = $this->inventory->recordRejectedOutput([
                    'item_name' => $data['item_name'],
                    'quantity' => $data['quantity'],
                    'cause' => $data['cause'],
                    'reason' => $data['reason'],
                    'product_variant_id' => $data['product_variant_id'] ?? null,
                    'sale_id' => $sale?->id,
                ], $data['lines'], Auth::id());

                $scrapSale = filled($data['scrap_revenue'] ?? null)
                    ? $rejected->sellAsScrap((float) $data['scrap_revenue'], $data['payment_method'], null, Auth::id())
                    : null;

                return [$rejected, $scrapSale];
            });

            return "{$rejected->reference} recorded as rejected. Material lost: ₱" . number_format($rejected->material_cost, 2) . '.'
                . ($sale ? " It comes off the profit of {$sale->invoice_number}." : '')
                . ($scrapSale ? ' Sold for ₱' . number_format($scrapSale->total_amount, 2) . " as {$scrapSale->invoice_number}, at ₱0.00 profit." : '');
        });
    }

    // Rejects sold as scrap or clearance: a new invoice in Sales, so the money is in the
    // cashier's cash count, but it counts as zero profit.
    public function sellScrap(Request $request, RejectedOutput $rejectedOutput)
    {
        $data = $request->validate([
            'scrap_revenue' => 'required|numeric|min:0.01|max:9999999',
            'payment_method' => 'required|in:cash,gcash',
            'scrap_note' => 'nullable|string|max:255',
        ]);

        return $this->attempt(function () use ($rejectedOutput, $data) {
            $sale = $rejectedOutput->sellAsScrap((float) $data['scrap_revenue'], $data['payment_method'], $data['scrap_note'] ?? null, Auth::id());

            return "{$rejectedOutput->reference} sold as scrap for ₱" . number_format($sale->total_amount, 2)
                . ". It's in Sales as {$sale->invoice_number} and counts as ₱0.00 profit.";
        });
    }

    public function discardRejected(RejectedOutput $rejectedOutput)
    {
        return $this->attempt(function () use ($rejectedOutput) {
            $rejected = DB::transaction(function () use ($rejectedOutput) {
                $rejected = RejectedOutput::whereKey($rejectedOutput->id)->lockForUpdate()->firstOrFail();
                if ($rejected->status !== 'rejected') {
                    throw new InventoryException("{$rejected->reference} is already " . ($rejected->status === 'scrap_sold' ? 'sold as scrap' : 'discarded') . '.');
                }
                $rejected->update(['status' => 'discarded']);

                return $rejected;
            });

            return "{$rejected->reference} marked as discarded.";
        });
    }

    // ------------------------------------------------------------------

    // The cards for the Continuous or Discrete page. A board's or roll's cut pieces show on its
    // card, not as cards of their own.
    private function board(string $type): array
    {
        // variants_count: how many product sizes list the material in their recipe (warned about on archive).
        $materials = Material::whereNull('archived_at')->where('inventory_type', $type)->whereNull('cut_from_id')
            ->withCount('variants')->with(['cutSizes' => fn ($q) => $q->whereNull('archived_at')])->orderBy('name')->get();
        $unset = Material::whereNull('archived_at')->whereNull('inventory_type')->whereNull('cut_from_id')->orderBy('name')->get();
        $archivedCount = Material::whereNotNull('archived_at')->whereNull('cut_from_id')->count();
        $pieces = $materials->flatMap->cutSizes;
        $ids = $materials->pluck('id')->merge($pieces->pluck('id'))->all();

        $usable = InventoryBatch::whereIn('material_id', $ids)->usable()
            ->orderBy('received_at')->orderBy('id')->get()->groupBy('material_id');

        $history = $this->history($materials, $pieces);

        $order = ['out' => 0, 'low' => 1, 'ok' => 2];
        $cards = $materials->map(function ($m) use ($usable, $history) {
            $batches = $usable->get($m->id, collect());
            $active = $batches->firstWhere('status', 'open');
            $sealedStore = $batches->where('status', 'unopened')->where('location', 'store')->values();
            $sealedWarehouse = $batches->where('status', 'unopened')->where('location', 'warehouse')->values();
            $stock = (float) $m->stock_quantity;
            $sheet = $m->isSheet() ? $this->sheetCard($m, $batches, $usable) : null;
            if ($sheet) {
                // Its cut pieces are still its stock, in board terms (as the stock alerts count them).
                $stock += $sheet['cuts']->sum(fn ($c) => $c['piece']->cut_yield > 0 ? $c['ready'] / $c['piece']->cut_yield * $m->sheetArea() : 0);
            }

            $state = $stock <= 0.0005 ? 'out' : ($stock <= (float) $m->low_stock_threshold ? 'low' : 'ok');
            $pct = ($active && (float) $active->opening_quantity > 0)
                ? (int) max(0, min(100, round($active->remaining_quantity / $active->opening_quantity * 100)))
                : 0;

            return compact('m', 'active', 'sealedStore', 'sealedWarehouse', 'state', 'pct', 'sheet') + [
                'history' => $history->get($m->id, collect())->take(8),
            ];
        })->sortBy(fn ($c) => [$order[$c['state']], strtolower($c['m']->name)])->values();

        $payloads = $cards->mapWithKeys(function ($c) use ($type) {
            $m = $c['m'];
            $active = $c['active'];
            $next = $c['sealedStore']->first();

            return [$m->id => [
                'id' => $m->id,
                'name' => $m->name,
                'code' => $m->code,
                'unit' => $m->unit,
                'kind' => $type,
                'stock' => (float) $m->stock_quantity,
                'used_by' => (int) $m->variants_count,
                'active_code' => $active?->batch_number,
                'active_left' => $active ? (float) $active->remaining_quantity : 0,
                'next_code' => $next?->batch_number,
                // Boards and rolls only: one's size, how many are whole in the store, and its cut sizes.
                'sheet' => $c['sheet'] ? [
                    'kind' => $m->sheet_kind,
                    'noun' => $m->sheetNoun(),
                    'label' => $m->sheetLabel(),
                    'area' => $m->sheetArea(),
                    'w' => (float) $m->sheet_width,
                    'h' => (float) $m->sheet_height,
                    'whole' => $c['sheet']['whole'],
                    'cuts' => $c['sheet']['cuts']->map(fn ($cut) => [
                        'id' => $cut['piece']->id,
                        'label' => $cut['piece']->cutLabel(),
                        'yield' => (int) $cut['piece']->cut_yield,
                        'ready' => $cut['ready'],
                        'trim' => $cut['trim'],
                        'url' => route('stock.cut-boards', $cut['piece']),
                        'remove' => route('stock.cut-sizes.destroy', $cut['piece']),
                    ])->values(),
                ] : null,
                'urls' => [
                    'restock' => $m->isSheet() ? route('stock.restock-sheets', $m) : route('materials.restock', $m),
                    'writeoff' => route('stock.write-off-board', $m),
                    'addsize' => route('stock.cut-sizes.store', $m),
                    'pull' => route('stock.pull', $m),
                    'open' => route('stock.open-next', $m),
                    'transfer' => route('stock.transfer', $m),
                    'loss' => route('stock.loss', $m),
                    'count' => route('stock.count', $m),
                    'archive' => route('materials.destroy', $m),
                ],
            ]];
        })->all();

        return compact('cards', 'payloads', 'unset', 'archivedCount');
    }

    // A board's or roll's card: how many are whole in the store, one part-used from before fixed
    // yields (if any), and each cut size with the pieces ready, their cost and the trim per board.
    private function sheetCard(Material $m, $batches, $usable): array
    {
        $whole = $batches->filter(fn ($b) => $b->location === 'store'
            && ($b->status === 'unopened' || (float) $b->remaining_quantity >= (float) $b->opening_quantity - 0.0005));
        $boardIn = $m->sheet_width * 12 * $m->sheet_height * 12;
        $cuts = $m->cutSizes->map(fn ($piece) => [
            'piece' => $piece,
            'ready' => (float) $usable->get($piece->id, collect())->where('location', 'store')->sum('remaining_quantity'),
            // Share of the board left over as trim, paid for in the pieces' cost.
            'trim' => $boardIn > 0 ? (int) round(100 * (1 - $piece->cut_yield * $piece->cut_width_in * $piece->cut_height_in / $boardIn)) : 0,
        ]);

        return [
            'whole' => $whole->count(),
            'warehouse' => $batches->where('status', 'unopened')->where('location', 'warehouse')->count(),
            'part_used' => $batches->first(fn ($b) => $b->status === 'open' && (float) $b->remaining_quantity < (float) $b->opening_quantity - 0.0005),
            'cuts' => $cuts,
            'ready' => $cuts->sum('ready'),
        ];
    }

    // Recent stock events per card, newest first: received batches, uses and adjustments. A board's
    // or roll's card also shows its pieces (cut from it, sold, spoiled), and counts boards, not sq ft.
    private function history($materials, $pieces)
    {
        $ids = $materials->pluck('id')->merge($pieces->pluck('id'))->all();
        if (! $ids) {
            return collect();
        }
        $sheets = $materials->filter->isSheet()->keyBy('id');
        $pieceOf = $pieces->keyBy('id');

        $received = InventoryBatch::whereIn('material_id', $ids)->with('receiver:id,name')
            ->orderByDesc('id')->limit(300)->get()
            ->map(fn ($b) => [
                'material_id' => $b->material_id,
                'at' => $b->created_at,
                'batch' => $b->batch_number,
                'what' => isset($pieceOf[$b->material_id])
                    ? (($sheets[$pieceOf[$b->material_id]->cut_from_id] ?? null)?->isRoll() ? 'Measured off a roll' : 'Cut from a board')
                    : ($b->purchase_order_item_id ? 'Received on PO' : 'Restocked'),
                'qty' => (float) $b->opening_quantity,
                'detail' => $b->location === 'warehouse' ? 'Warehouse' : null,
                'by' => $b->receiver->name ?? null,
            ]);

        $used = BatchConsumption::join('inventory_batches as b', 'b.id', '=', 'batch_consumptions.inventory_batch_id')
            ->leftJoin('sales as s', 's.id', '=', 'batch_consumptions.sale_id')
            ->leftJoin('users as u', 'u.id', '=', 'batch_consumptions.user_id')
            ->whereIn('b.material_id', $ids)
            ->orderByDesc('batch_consumptions.id')->limit(600)
            ->get(['batch_consumptions.*', 'b.material_id', 'b.batch_number', 's.invoice_number', 'u.name as user_name'])
            ->map(fn ($c) => [
                'material_id' => $c->material_id,
                'at' => $c->created_at,
                'batch' => $c->batch_number,
                'what' => $c->sale_item_id ? 'Sale' : ($c->inventory_movement_id ? 'Production'
                    : (isset($sheets[$c->material_id]) ? 'Cut into pieces' : 'Pulled for use')),
                'qty' => -1 * (float) $c->quantity_consumed,
                'detail' => trim(implode(', ', array_filter([$c->invoice_number, $c->note]))) ?: null,
                'by' => $c->user_name,
            ]);

        $labels = [
            'damaged' => 'Damaged', 'wasted' => 'Rejected output', 'count_correction' => 'Count',
            'void_return' => 'Sale voided', 'to_offcut' => 'Kept as offcut', 'write_off' => 'Written off',
        ];
        $adjusted = BatchAdjustment::join('inventory_batches as b', 'b.id', '=', 'batch_adjustments.inventory_batch_id')
            ->leftJoin('users as u', 'u.id', '=', 'batch_adjustments.user_id')
            ->whereIn('b.material_id', $ids)
            ->orderByDesc('batch_adjustments.id')->limit(600)
            ->get(['batch_adjustments.*', 'b.material_id', 'b.batch_number', 'u.name as user_name'])
            ->map(fn ($a) => [
                'material_id' => $a->material_id,
                'at' => $a->created_at,
                'batch' => $a->batch_number,
                'what' => $labels[$a->type] ?? ucfirst($a->type),
                'qty' => (float) $a->quantity,
                'detail' => $a->reason,
                'by' => $a->user_name,
            ]);

        // A piece's events go on its board's card, in pieces ("Long") or feet. A board's own amounts
        // are whole boards (or rolls), not sq ft.
        return $received->concat($used)->concat($adjusted)
            ->map(function ($e) use ($sheets, $pieceOf) {
                if (isset($pieceOf[$e['material_id']])) {
                    $piece = $pieceOf[$e['material_id']];
                    $e['material_id'] = $piece->cut_from_id;
                    $e['unit'] = ($sheets[$piece->cut_from_id] ?? null)?->isRoll() ? 'ft' : preg_replace('/\s*\(.*\)$/', '', $piece->cutLabel());
                } elseif (isset($sheets[$e['material_id']]) && $sheets[$e['material_id']]->sheetArea() > 0) {
                    $sheet = $sheets[$e['material_id']];
                    $e['qty'] = round($e['qty'] / $sheet->sheetArea(), 3);
                    $e['unit'] = $sheet->sheetNoun() . (abs($e['qty']) == 1 ? '' : 's');
                }
                return $e;
            })
            ->sortByDesc(fn ($e) => optional($e['at'])->timestamp ?? 0)
            ->groupBy('material_id');
    }

    // A board (Discrete page) or a roll (Continuous page) of a set size, counted whole. A board
    // starts with one cut size, the whole board; add the shop's sizes on its card. A roll is
    // measured by the foot.
    private function storeSheet(Request $request)
    {
        $data = $request->validate([
            'inventory_type' => 'required|in:continuous,discrete',
            'name' => 'required|string|max:150',
            'sheet_width' => 'required|numeric|min:0.5|max:50',
            'sheet_height' => 'required|numeric|min:0.5|max:1000',
            'sheets' => 'nullable|integer|min:0|max:500',
            'reorder_sheets' => 'nullable|numeric|min:0|max:500',
            'cost_per_sheet' => 'nullable|numeric|min:0',
        ]);
        $roll = $data['inventory_type'] === 'continuous';
        if (! $roll && $data['sheet_height'] > 50) {
            return back()->withInput()->with('error', 'A board is at most 50 ft long. Is it a roll? Add it on the Continuous Raw Materials page.');
        }
        $area = round($data['sheet_width'] * $data['sheet_height'], 3);
        $costPerSheet = isset($data['cost_per_sheet']) ? (float) $data['cost_per_sheet'] : null;

        try {
            $material = DB::transaction(function () use ($data, $roll, $area, $costPerSheet) {
                $material = new Material();
                $material->forceFill([
                    'inventory_type' => $data['inventory_type'],
                    'sheet_kind' => $roll ? 'roll' : 'board',
                    'name' => $data['name'],
                    'unit' => 'sq ft',
                    'sheet_width' => $data['sheet_width'],
                    'sheet_height' => $data['sheet_height'],
                    'pack_size' => $area,
                    'stock_quantity' => 0,
                    // Alert when this many are left. One if left blank.
                    'low_stock_threshold' => round(($data['reorder_sheets'] ?? 1) * $area, 3),
                    'cost_per_unit' => $costPerSheet !== null ? round($costPerSheet / $area, 2) : null,
                ])->save();
                $this->inventory->ensureCode($material);

                $w = (float) $data['sheet_width'] * 12;
                $roll
                    ? $this->makePiece($material, 'per foot', $w, 12)
                    : $this->makePiece($material, 'Whole board (' . $this->fmt($data['sheet_width']) . ' x ' . $this->fmt($data['sheet_height']) . ' ft)',
                        $w, (float) $data['sheet_height'] * 12);

                if ((int) ($data['sheets'] ?? 0) > 0) {
                    $this->inventory->restockSheets($material, (int) $data['sheets'], 'store', Auth::id(), $costPerSheet);
                } else {
                    $this->inventory->refreshMaterial($material);
                }

                return $material;
            });
        } catch (InventoryException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', $roll
            ? "{$material->name} added. Each {$material->sheetLabel()} roll is measured into " . (int) $data['sheet_height'] . ' one-foot blocks as it is used.'
            : "{$material->name} added. Add the sizes you cut it into on its card: each gets its yield from one {$material->sheetLabel()} board.");
    }

    // The sale for an invoice number typed on a form. Null when left blank.
    // A cut size of a board or roll: a discrete material of its own that holds the cut pieces.
    // w and h in inches. A roll's is one foot of it at full width.
    private function makePiece(Material $board, string $label, float $w, float $h): Material
    {
        $piece = new Material();
        $piece->forceFill([
            'name' => "{$board->name} - {$label}",
            'unit' => $board->isRoll() ? 'ft' : 'pcs',
            'inventory_type' => 'discrete',
            'stock_quantity' => 0,
            'low_stock_threshold' => 0,
            'cut_from_id' => $board->id,
            'cut_width_in' => $w,
            'cut_height_in' => $h,
            'cut_yield' => SheetYield::perSheet($board->sheet_width * 12, $board->sheet_height * 12, $w, $h),
        ])->save();
        $this->inventory->ensureCode($piece);

        return $piece->setRelation('cutFrom', $board);
    }

    // Products sold cut by size from this board or roll (their recipe lists it).
    private function itemsCutFrom(Material $board): array
    {
        return ProductVariant::whereHas('materials', fn ($q) => $q->where('materials.id', $board->id))
            ->distinct()->pluck('product_id')->all();
    }

    // Each item cut from the board gets the new size as a type, made from what its other types are.
    private function addSizeToItems(Material $board, string $label, float $w, float $h): void
    {
        foreach (Product::whereIn('id', $this->itemsCutFrom($board))->with('variants.materials')->get() as $product) {
            $model = $product->variants->first(fn ($v) => $v->materials->contains('id', $board->id));
            $type = $product->variants()->create([
                'variant_name' => $label,
                'sku' => $this->freeVariantSku($product->sku . '-' . trim(strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', $label)), '-')),
                'attributes' => ['width_in' => $w, 'height_in' => $h],
                'price' => null,
                'stock_quantity' => 0,
                'low_stock_threshold' => 0,
                'reorder_point' => 0,
                'status' => 'active',
            ]);
            foreach ($model->materials as $stock) {
                $type->materials()->attach($stock->id, [
                    'quantity_per_unit' => $stock->pivot->quantity_per_unit,
                    'consumption_type' => $stock->pivot->consumption_type,
                ]);
            }
        }
    }

    // The SKU, or SKU-2, SKU-3... if a size already uses it.
    private function freeVariantSku(string $sku): string
    {
        $free = $sku;
        for ($i = 2; ProductVariant::where('sku', $free)->exists(); $i++) {
            $free = $sku . '-' . $i;
        }

        return $free;
    }

    private function invoice(?string $number): ?Sale
    {
        if (! filled($number)) {
            return null;
        }

        return Sale::where('invoice_number', trim($number))->first()
            ?? throw new InventoryException("There is no invoice {$number}. Check the number, or leave it blank.");
    }

    private function attempt(callable $action)
    {
        try {
            return back()->with('success', $action());
        } catch (InventoryException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    private function pageName(?string $type): string
    {
        return $type === 'continuous' ? 'Continuous Raw Materials' : 'Discrete Materials';
    }

    private function archived(Material $material)
    {
        return $material->archived_at
            ? back()->with('error', "{$material->name} is archived. Restore it on the Material list first.")
            : null;
    }

    private function fmt($value): string
    {
        return StockAlertService::formatQty($value);
    }
}
