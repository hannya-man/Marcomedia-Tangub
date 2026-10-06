<?php
namespace App\Http\Controllers;

use App\Models\BatchAdjustment;
use App\Models\BatchConsumption;
use App\Models\InventoryBatch;
use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\RejectedOutput;
use App\Models\Sale;
use App\Services\Inventory\BatchInventoryService;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\StockAlertService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Three of the four inventory types. All stock lives in batches (inventory_batches),
 * and every change goes through BatchInventoryService.
 *   Continuous Raw Materials   - fabric rolls, thread, liquids. Staff log each pull for use.
 *                                Sheet materials (sintra board) are logged per job instead:
 *                                a size cut from the open sheet, or a whole sheet.
 *   Discrete Materials         - PVC cards, RFID chips, fasteners. Deducted 1 to 1 by sales.
 *   Scrapped / Rejected Output - failed production runs, reprints, and scrap sold from them.
 * The fourth, Products, is the existing Stock page (InventoryController).
 * Restock goes to MaterialController::adjustStock, which makes a new batch without a PO.
 * Sheet materials restock by the sheet instead (restockSheets), one batch per sheet.
 */
class StockController extends Controller
{
    public function __construct(private BatchInventoryService $inventory)
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

        $materials = Material::whereNull('archived_at')->orderBy('name')
            ->get(['id', 'name', 'unit', 'inventory_type', 'sheet_width', 'sheet_height']);

        // "Mistake" on a sheet material's card opens the form with that material filled in.
        $recordFor = $materials->firstWhere('id', (int) $request->query('record'))?->id;

        // Product sizes with a recipe, so a rejected run can fill in its materials.
        $recipes = ProductVariant::with(['product:id,name', 'materials:id,inventory_type'])
            ->whereHas('materials')
            ->get()
            ->map(function ($v) {
                return [
                    'id' => $v->id,
                    'label' => ($v->product->name ?? 'Product') . ' - ' . $v->variant_name,
                    'lines' => $v->materials->map(function ($m) {
                        return [
                            'material_id' => $m->id,
                            'per_unit' => (float) $m->pivot->quantity_per_unit,
                            'fixed' => ($m->pivot->consumption_type ?? 'fixed') === 'fixed',
                            'continuous' => $m->inventory_type === 'continuous',
                        ];
                    })->values(),
                ];
            })
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

    // Sheet materials only (sintra board): a job cut to size from the open sheet ("by batch"),
    // or a job that takes a whole sheet ("full").
    public function cut(Request $request, Material $material)
    {
        if ($blocked = $this->archived($material)) {
            return $blocked;
        }

        $data = $request->validate([
            'mode' => 'required|in:size,whole',
            'width' => 'exclude_unless:mode,size|required|numeric|min:0.01|max:1000',
            'height' => 'exclude_unless:mode,size|required|numeric|min:0.01|max:1000',
            'size_unit' => 'exclude_unless:mode,size|required|in:in,ft',
            'pieces' => 'exclude_unless:mode,size|required|integer|min:1|max:1000',
            'which' => 'exclude_unless:mode,whole|required|in:rest,new',
            'note' => 'nullable|string|max:255',
            'invoice' => 'nullable|string|max:30',
        ]);

        return $this->attempt(function () use ($material, $data) {
            $sale = $this->invoice($data['invoice'] ?? null);
            $rows = $data['mode'] === 'size'
                ? $this->inventory->cutFromSheet($material, (float) $data['width'], (float) $data['height'], $data['size_unit'],
                    (int) $data['pieces'], $data['note'] ?? null, Auth::id(), $sale?->id)
                : $this->inventory->useWholeSheet($material, $data['which'], $data['note'] ?? null, Auth::id(), $sale?->id);
            $sheets = $rows->map(fn ($row) => $row->batch->batch_number)->unique()->implode(' and ');

            return 'Took ' . $this->fmt($rows->sum('quantity_consumed')) . " {$material->unit} of {$material->name} from {$sheets}"
                . ($sale ? " for {$sale->invoice_number}" : '') . '. '
                . $this->fmt($material->fresh()->stock_quantity) . " {$material->unit} left in stock.";
        });
    }

    // Sheet materials restock by the sheet: each sheet becomes its own sealed batch.
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

            return "Restocked {$batches->count()} " . ($batches->count() === 1 ? 'sheet' : 'sheets') . " of {$material->name}: {$numbers}.";
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

            return "{$pack->batch_number} moved to the store.";
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

            return $adjustment
                ? "Count saved on {$active->batch_number}: " . ($adjustment->quantity > 0 ? '+' : '') . $this->fmt($adjustment->quantity) . " {$material->unit}."
                : 'The count matches the system. Nothing changed.';
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

    // The cards for the Continuous or Discrete page.
    private function board(string $type): array
    {
        // variants_count: how many product sizes list the material in their recipe (warned about on archive).
        $materials = Material::whereNull('archived_at')->where('inventory_type', $type)->withCount('variants')->orderBy('name')->get();
        $unset = Material::whereNull('archived_at')->whereNull('inventory_type')->orderBy('name')->get();
        $archivedCount = Material::whereNotNull('archived_at')->count();
        $ids = $materials->pluck('id')->all();

        $usable = InventoryBatch::whereIn('material_id', $ids)->usable()
            ->orderBy('received_at')->orderBy('id')->get()->groupBy('material_id');

        $history = $this->history($ids, $materials->filter->isSheet()->pluck('id')->all());

        $order = ['out' => 0, 'low' => 1, 'ok' => 2];
        $cards = $materials->map(function ($m) use ($usable, $history) {
            $batches = $usable->get($m->id, collect());
            $active = $batches->firstWhere('status', 'open');
            $sealedStore = $batches->where('status', 'unopened')->where('location', 'store')->values();
            $sealedWarehouse = $batches->where('status', 'unopened')->where('location', 'warehouse')->values();
            $stock = (float) $m->stock_quantity;

            $state = $stock <= 0 ? 'out' : ($stock <= (float) $m->low_stock_threshold ? 'low' : 'ok');
            $pct = ($active && (float) $active->opening_quantity > 0)
                ? (int) max(0, min(100, round($active->remaining_quantity / $active->opening_quantity * 100)))
                : 0;

            return compact('m', 'active', 'sealedStore', 'sealedWarehouse', 'state', 'pct') + [
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
                'unit' => $m->unit,
                'kind' => $type,
                'stock' => (float) $m->stock_quantity,
                'used_by' => (int) $m->variants_count,
                'active_code' => $active?->batch_number,
                'active_left' => $active ? (float) $active->remaining_quantity : 0,
                'next_code' => $next?->batch_number,
                // Sheet materials only (sintra board): one sheet's size, and whether the open sheet is still whole.
                'sheet' => $m->isSheet() ? [
                    'label' => $m->sheetLabel(),
                    'area' => $m->sheetArea(),
                    'w' => (float) $m->sheet_width,
                    'h' => (float) $m->sheet_height,
                ] : null,
                'active_whole' => $active && abs((float) $active->remaining_quantity - (float) $active->opening_quantity) < 0.0005,
                'urls' => [
                    'restock' => $m->isSheet() ? route('stock.restock-sheets', $m) : route('materials.restock', $m),
                    'cut' => route('stock.cut', $m),
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

    // Recent stock events per material: received batches, uses and adjustments, newest first.
    // $sheetIds: sheet materials, whose manual uses are job cuts rather than pulls.
    private function history(array $ids, array $sheetIds = [])
    {
        if (! $ids) {
            return collect();
        }

        $received = InventoryBatch::whereIn('material_id', $ids)->with('receiver:id,name')
            ->orderByDesc('id')->limit(300)->get()
            ->map(fn ($b) => [
                'material_id' => $b->material_id,
                'at' => $b->created_at,
                'batch' => $b->batch_number,
                'what' => $b->purchase_order_item_id ? 'Received on PO' : 'Restocked',
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
                    : (in_array($c->material_id, $sheetIds) ? 'Cut for a job' : 'Pulled for use')),
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

        return $received->concat($used)->concat($adjusted)
            ->sortByDesc(fn ($e) => optional($e['at'])->timestamp ?? 0)
            ->groupBy('material_id');
    }

    // A continuous material cut from whole sheets, like sintra board. Stock is in sq ft,
    // and the sheets on hand become its first batches, one per sheet.
    private function storeSheet(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'sheet_width' => 'required|numeric|min:0.5|max:50',
            'sheet_height' => 'required|numeric|min:0.5|max:50',
            'sheets' => 'nullable|integer|min:0|max:500',
            'reorder_sheets' => 'nullable|numeric|min:0|max:500',
            'cost_per_sheet' => 'nullable|numeric|min:0',
        ]);
        $area = round($data['sheet_width'] * $data['sheet_height'], 3);
        $costPerSheet = isset($data['cost_per_sheet']) ? (float) $data['cost_per_sheet'] : null;

        try {
            $material = DB::transaction(function () use ($data, $area, $costPerSheet) {
                $material = new Material();
                $material->forceFill([
                    'inventory_type' => 'continuous',
                    'name' => $data['name'],
                    'unit' => 'sq ft',
                    'sheet_width' => $data['sheet_width'],
                    'sheet_height' => $data['sheet_height'],
                    'pack_size' => $area,
                    'stock_quantity' => 0,
                    // Alert when less than this many sheets are left. One sheet if left blank.
                    'low_stock_threshold' => round(($data['reorder_sheets'] ?? 1) * $area, 3),
                    'cost_per_unit' => $costPerSheet !== null ? round($costPerSheet / $area, 2) : null,
                ])->save();
                $this->inventory->ensureCode($material);

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

        return back()->with('success', "{$material->name} added to Continuous Raw Materials. Each {$material->sheetLabel()} sheet is "
            . $this->fmt($area) . ' sq ft and gets its own batch number.');
    }

    // The sale for an invoice number typed on a form. Null when left blank.
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
