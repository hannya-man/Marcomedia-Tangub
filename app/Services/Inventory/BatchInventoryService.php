<?php
namespace App\Services\Inventory;

use App\Models\BatchAdjustment;
use App\Models\BatchConsumption;
use App\Models\InventoryBatch;
use App\Models\Material;
use App\Models\MaterialOffcut;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\SaleItem;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The only place batch stock changes.
 *
 * Rules:
 *  1. Stock comes in only as batches: one sealed batch per pack received on a PO
 *     (or opening stock recorded at go-live).
 *  2. One open (active) batch per material. Opening a new one closes the old one.
 *  3. Jobs and production runs take from the active batch. When it reaches 0 it closes
 *     and the oldest sealed pack in the store opens, so a sale is never blocked by paperwork.
 *  4. Anything that is not a job (breakage, miscuts, counts, voids, leftovers) is a signed
 *     row in batch_adjustments. Every batch balances:
 *        opening_quantity - SUM(consumptions) + SUM(adjustments) = remaining_quantity
 *  5. materials.stock_quantity = total of unclosed batches. Alerts are re-checked on every change.
 *
 * Lock order is always: material -> purchase order -> batches. Same order everywhere = no deadlocks.
 */
class BatchInventoryService
{
    public const EPSILON = 0.0005;

    public function __construct(private StockAlertService $alerts)
    {
    }

    // =====================================================================
    // 1. Purchase orders and deliveries
    // =====================================================================

    /**
     * @param array $lines each: material_id, packs, qty_per_pack, cost_per_pack (optional)
     *                     A monthly bundle of 5 Central Board packs is ONE line with packs = 5.
     */
    public function createPurchaseOrder(Supplier $supplier, array $lines, ?int $userId, ?string $notes = null, $expectedAt = null, $orderedAt = null): PurchaseOrder
    {
        if ($supplier->archived_at) {
            throw new InventoryException("{$supplier->name} is archived. Restore the supplier first.");
        }
        if (empty($lines)) {
            throw new InventoryException('Add at least one material to the purchase order.');
        }

        return DB::transaction(function () use ($supplier, $lines, $userId, $notes, $expectedAt, $orderedAt) {
            $this->lockMaterials(array_column($lines, 'material_id'));
            $orderedAt = $orderedAt ? Carbon::parse($orderedAt) : now();

            $po = PurchaseOrder::create([
                'po_number' => 'NEW-' . uniqid(),
                'supplier_id' => $supplier->id,
                'status' => 'ordered',
                'ordered_at' => $orderedAt->toDateString(),
                'expected_at' => $expectedAt ? Carbon::parse($expectedAt)->toDateString() : null,
                'notes' => $notes,
                'created_by' => $userId,
            ]);
            // PO-2026-0001. Built from the row id, so two people saving at once never get the same number.
            $po->update(['po_number' => 'PO-' . $orderedAt->format('Y') . '-' . str_pad((string) $po->id, 4, '0', STR_PAD_LEFT)]);

            $seen = [];
            foreach ($lines as $line) {
                $material = Material::findOrFail($line['material_id']);
                $packs = (int) ($line['packs'] ?? 0);
                $perPack = $this->q($line['qty_per_pack'] ?? $material->pack_size ?? 0);

                if ($material->archived_at) {
                    throw new InventoryException("{$material->name} is archived.");
                }
                if (isset($seen[$material->id])) {
                    throw new InventoryException("{$material->name} is listed twice. Put all its packs on one line.");
                }
                if ($packs < 1) {
                    throw new InventoryException("Enter how many packs of {$material->name} were ordered.");
                }
                if ($perPack <= 0) {
                    throw new InventoryException("Enter how many {$material->unit} are in one pack of {$material->name}.");
                }
                $seen[$material->id] = true;

                $po->items()->create([
                    'material_id' => $material->id,
                    'packs_ordered' => $packs,
                    'qty_per_pack' => $perPack,
                    'cost_per_pack' => $line['cost_per_pack'] ?? null,
                ]);
            }

            // Alerts now say "already on order", so nobody orders twice.
            foreach (Material::whereIn('id', array_keys($seen))->get() as $material) {
                $this->alerts->evaluate($material);
            }

            return $po->load('items');
        });
    }

    /**
     * Check in packs that arrived for one PO line. Each pack becomes its own sealed batch:
     * SEPT-2026-CB-1, SEPT-2026-CB-2, ...
     * A short pack (98 sheets instead of 100) is received as ordered, then fixed with recordCount(),
     * so the shortfall stays on record for the supplier.
     */
    public function receive(PurchaseOrderItem $item, int $packs, string $location, ?int $userId, $receivedAt = null): Collection
    {
        $this->assertLocation($location);
        if ($packs < 1) {
            throw new InventoryException('Enter how many packs arrived.');
        }

        return DB::transaction(function () use ($item, $packs, $location, $userId, $receivedAt) {
            $material = $this->lockMaterial($item->material_id);
            $po = PurchaseOrder::whereKey($item->purchase_order_id)->lockForUpdate()->firstOrFail();
            $item = PurchaseOrderItem::findOrFail($item->id);

            if ($po->status === 'cancelled') {
                throw new InventoryException("{$po->po_number} was cancelled. Make a new PO for this delivery.");
            }
            if ($material->archived_at) {
                throw new InventoryException("{$material->name} is archived. Restore it before receiving.");
            }
            $pending = $item->packs_ordered - $item->batches()->count();
            if ($packs > $pending) {
                throw new InventoryException("{$po->po_number} has only {$pending} pack(s) of {$material->name} still due. Put extra packs on a new PO.");
            }

            $date = $receivedAt ? Carbon::parse($receivedAt) : now();
            $costPerUnit = $item->cost_per_pack !== null
                ? round($item->cost_per_pack / $item->qty_per_pack, 2)
                : $material->cost_per_unit;

            $batches = collect();
            for ($i = 0; $i < $packs; $i++) {
                $batches->push($this->createBatch($material, $date, [
                    'purchase_order_item_id' => $item->id,
                    'location' => $location,
                    'opening_quantity' => $item->qty_per_pack,
                    'remaining_quantity' => $item->qty_per_pack,
                    'cost_per_unit' => $costPerUnit,
                    'received_by' => $userId,
                ]));
            }

            $po->refreshStatus();
            $this->afterStockChange($material);

            return $batches;
        });
    }

    public function cancelPurchaseOrder(PurchaseOrder $po, ?string $reason = null): PurchaseOrder
    {
        return DB::transaction(function () use ($po, $reason) {
            $materialIds = PurchaseOrderItem::where('purchase_order_id', $po->id)->pluck('material_id')->all();
            $this->lockMaterials($materialIds);
            $po = PurchaseOrder::whereKey($po->id)->lockForUpdate()->firstOrFail();

            if (in_array($po->status, ['received', 'cancelled'], true)) {
                throw new InventoryException("{$po->po_number} is already {$po->status}.");
            }

            $note = 'Cancelled' . ($reason ? ": {$reason}" : '');
            $po->update([
                'status' => 'cancelled',
                'notes' => Str::limit($po->notes ? "{$po->notes} | {$note}" : $note, 250),
            ]);

            foreach (Material::whereIn('id', $materialIds)->get() as $material) {
                $this->alerts->evaluate($material);
            }

            return $po;
        });
    }

    /** Stock already on the shelf at go-live (no PO). Every delivery after that uses receive(). */
    public function recordOpeningStock(Material $material, float $quantity, string $location, ?int $userId, ?float $costPerUnit = null, $receivedAt = null): InventoryBatch
    {
        $this->assertLocation($location);
        $qty = $this->q($quantity);
        if ($qty <= 0) {
            throw new InventoryException('Opening stock must be more than zero.');
        }

        return DB::transaction(function () use ($material, $qty, $location, $userId, $costPerUnit, $receivedAt) {
            $material = $this->lockMaterial($material->id);
            if ($material->archived_at) {
                throw new InventoryException("{$material->name} is archived.");
            }

            $batch = $this->createBatch($material, $receivedAt ? Carbon::parse($receivedAt) : now(), [
                'purchase_order_item_id' => null,
                'location' => $location,
                'opening_quantity' => $qty,
                'remaining_quantity' => $qty,
                'cost_per_unit' => $costPerUnit ?? $material->cost_per_unit,
                'received_by' => $userId,
            ]);

            $this->afterStockChange($material);

            return $batch;
        });
    }

    // =====================================================================
    // 2. Opening, closing, moving batches
    // =====================================================================

    /**
     * Owner/manager opens a pack. The current active batch closes in the same step (Sir Jay's rule).
     * If the old batch still has stock, $leftover must say where it went:
     *   ['offcuts' => [['label' => '12 x 18 in piece', 'quantity' => 216, 'width' => 12, 'height' => 18]],
     *    'write_off_reason' => 'Edges chipped']
     */
    public function openBatch(InventoryBatch $batch, ?int $userId, array $leftover = []): InventoryBatch
    {
        return DB::transaction(function () use ($batch, $userId, $leftover) {
            $material = $this->lockMaterial($batch->material_id);
            $batch = $this->lockBatch($batch->id);

            if ($batch->status === 'open') {
                throw new InventoryException("{$batch->batch_number} is already the active batch.");
            }
            if ($batch->status === 'closed') {
                throw new InventoryException("{$batch->batch_number} is closed and can't be opened again.");
            }
            if ($batch->location !== 'store') {
                throw new InventoryException("{$batch->batch_number} is still in the warehouse. Move it to the store first.");
            }

            $current = $this->currentOpenBatch($material->id);
            if ($current) {
                $this->settleLeftover($current, $leftover, $userId);
                $this->markClosed($current, 'replaced', $userId);
            }

            $this->markOpened($batch, 'manual', $userId);
            $this->afterStockChange($material);

            return $batch;
        });
    }

    /** Close a batch by hand, e.g. the pack is used up but the system still shows some left. */
    public function closeBatch(InventoryBatch $batch, ?int $userId, array $leftover = []): InventoryBatch
    {
        return DB::transaction(function () use ($batch, $userId, $leftover) {
            $material = $this->lockMaterial($batch->material_id);
            $batch = $this->lockBatch($batch->id);

            if ($batch->status === 'closed') {
                throw new InventoryException("{$batch->batch_number} is already closed.");
            }

            $this->settleLeftover($batch, $leftover, $userId);
            $this->markClosed($batch, 'manual', $userId);
            $this->afterStockChange($material);

            return $batch;
        });
    }

    /** Move a sealed pack from the warehouse to the store, so it can be opened and used. */
    public function transferToStore(InventoryBatch $batch): InventoryBatch
    {
        return DB::transaction(function () use ($batch) {
            $material = $this->lockMaterial($batch->material_id);
            $batch = $this->lockBatch($batch->id);

            if ($batch->location === 'store') {
                throw new InventoryException("{$batch->batch_number} is already in the store.");
            }
            if ($batch->status !== 'unopened') {
                throw new InventoryException("Only sealed packs can be moved. {$batch->batch_number} is {$batch->status}.");
            }

            $batch->forceFill(['location' => 'store', 'transferred_at' => now()])->save();
            $this->afterStockChange($material);

            return $batch;
        });
    }

    // =====================================================================
    // 3. Deducting stock (the batch decrement logic)
    // =====================================================================

    /**
     * Take $quantity from the active batch. If the active batch runs out it closes
     * (close_reason = empty) and the oldest sealed pack in the store opens, until the
     * full amount is taken. All or nothing: if the store is short, nothing is deducted.
     *
     * @param array $link sale_id, sale_item_id, inventory_movement_id, revenue
     * @return Collection BatchConsumption rows. Two rows = the job crossed into a new pack.
     */
    public function consume(Material $material, float $quantity, array $link, ?int $userId): Collection
    {
        $qty = $this->q($quantity);
        if ($qty <= 0) {
            throw new InventoryException('Quantity to deduct must be more than zero.');
        }

        return DB::transaction(function () use ($material, $qty, $link, $userId) {
            // Step 1. Lock the material row. Two cashiers can't take the same sheet.
            $material = $this->lockMaterial($material->id);
            if ($material->archived_at) {
                throw new InventoryException("{$material->name} is archived and can't be used.");
            }

            // Step 2. Check the whole amount first (active batch + sealed packs in the store).
            $available = $this->storeAvailable($material->id);
            if ($qty - $available > self::EPSILON) {
                $message = "Not enough {$material->name} in the store: need {$this->fmt($qty)} {$material->unit}, have {$this->fmt($available)}.";
                $warehouse = $this->q(InventoryBatch::where('material_id', $material->id)
                    ->where('location', 'warehouse')->where('status', 'unopened')->sum('remaining_quantity'));
                if ($warehouse > 0) {
                    $message .= " {$this->fmt($warehouse)} {$material->unit} is in the warehouse. Move a pack to the store first.";
                }
                throw new InventoryException($message);
            }

            $revenue = round((float) ($link['revenue'] ?? 0), 2);
            $revenueLeft = $revenue;
            $left = $qty;
            $rows = collect();

            while ($left > self::EPSILON) {
                // Step 3. Use the active batch. None open? Open the oldest sealed store pack.
                $batch = $this->currentOpenBatch($material->id) ?? $this->autoOpenNext($material, $userId);

                // Step 4. Take what this batch can give and log it against the line item.
                $take = $this->q(min($left, (float) $batch->remaining_quantity));
                $left = $this->q($left - $take);
                $share = $left > self::EPSILON ? round($revenue * $take / $qty, 2) : $revenueLeft;
                $revenueLeft = round($revenueLeft - $share, 2);

                if ($take > 0) {
                    $rows->push($batch->consumptions()->create([
                        'sale_id' => $link['sale_id'] ?? null,
                        'sale_item_id' => $link['sale_item_id'] ?? null,
                        'inventory_movement_id' => $link['inventory_movement_id'] ?? null,
                        'quantity_consumed' => $take,
                        'revenue' => $share,
                        'created_at' => now(),
                    ]));
                    $batch->remaining_quantity = $this->q($batch->remaining_quantity - $take);
                    $batch->save();
                }

                // Step 5. Batch close event: balance reached zero.
                if ($batch->remaining_quantity <= self::EPSILON) {
                    $this->markClosed($batch, 'empty', $userId);
                }
            }

            // Step 6. Update the stock total and re-check alerts.
            $this->afterStockChange($material);

            return $rows;
        });
    }

    /**
     * Made-to-order line (product with Track inventory OFF): take every material in the
     * size's bill of materials from its active batch, linked to this line item.
     * Finished goods (Track inventory ON) return nothing here: their materials were used at production.
     *
     * @param array $offcutIds offcuts staff picked for this job (one per material). That material
     *                         is then taken from the offcut instead of the batch.
     */
    public function consumeForSaleItem(SaleItem $item, ?int $userId, array $offcutIds = []): Collection
    {
        $offcutIds = array_values(array_unique(array_map('intval', $offcutIds)));
        $product = Product::find($item->product_id);

        if (!$item->product_variant_id || ($product && $product->track_inventory)) {
            if ($offcutIds) {
                throw new InventoryException('Offcuts can only be used on made-to-order items.');
            }
            return collect();
        }

        return DB::transaction(function () use ($item, $userId, $offcutIds) {
            $variant = ProductVariant::findOrFail($item->product_variant_id);
            $lines = $variant->materials()->withPivot('consumption_type')->get()->values();
            if ($lines->isEmpty()) {
                if ($offcutIds) {
                    throw new InventoryException("{$item->item_name} has no materials listed, so it can't use an offcut.");
                }
                return collect();
            }

            // Lock every material this item uses, lowest id first, before touching anything.
            $this->lockMaterials($lines->pluck('id')->all());

            $needs = $lines->map(function ($material) use ($item) {
                return $this->bomQuantity($material, $item);
            })->all();
            $offcuts = $this->claimableOffcuts($offcutIds, $lines);
            $shares = $this->splitRevenue((float) $item->subtotal, $lines, $needs);

            $rows = collect();
            foreach ($lines as $i => $material) {
                if ($needs[$i] <= 0) {
                    continue;
                }

                $offcut = $offcuts->pull($material->id);
                if ($offcut) {
                    $this->assertOffcutFits($offcut, $needs[$i], $item, $material);
                    $offcut->update(['status' => 'used', 'used_for_sale_item_id' => $item->id, 'used_at' => now()]);
                    continue;
                }

                $rows = $rows->merge($this->consume($material, $needs[$i], [
                    'sale_id' => $item->sale_id,
                    'sale_item_id' => $item->id,
                    'revenue' => $shares[$i] ?? 0,
                ], $userId));
            }

            return $rows;
        });
    }

    /** Finished goods produced ahead (Track inventory ON): materials are used at stock_in. */
    public function consumeForProduction(int $variantId, int $units, ?int $movementId, ?int $userId): Collection
    {
        if ($units < 1) {
            return collect();
        }

        return DB::transaction(function () use ($variantId, $units, $movementId, $userId) {
            $variant = ProductVariant::findOrFail($variantId);
            $lines = $variant->materials()->withPivot('consumption_type')->get();
            $this->lockMaterials($lines->pluck('id')->all());

            $rows = collect();
            foreach ($lines as $material) {
                if (($material->pivot->consumption_type ?? 'fixed') !== 'fixed') {
                    throw new InventoryException("{$variant->variant_name} uses {$material->name} by size. A production run needs a fixed amount per unit.");
                }
                $need = $this->q($material->pivot->quantity_per_unit * $units);
                if ($need > 0) {
                    $rows = $rows->merge($this->consume($material, $need, ['inventory_movement_id' => $movementId], $userId));
                }
            }

            return $rows;
        });
    }

    /**
     * Voided job: put back what it took, once. Safe to call twice.
     * Returned material goes onto the active batch (source batch noted). If nothing is open,
     * the batch it came from becomes active again. Offcuts it used become available again.
     * If the material was already cut, record that cut piece as waste or an offcut afterwards.
     */
    public function returnSaleItem(SaleItem $item, ?int $userId, ?string $reason = null): void
    {
        DB::transaction(function () use ($item, $userId, $reason) {
            $rows = BatchConsumption::where('sale_item_id', $item->id)->get();
            $materialIds = InventoryBatch::whereIn('id', $rows->pluck('inventory_batch_id')->unique()->all())->pluck('material_id')
                ->merge(MaterialOffcut::where('used_for_sale_item_id', $item->id)->pluck('material_id'))
                ->unique()->all();
            $this->lockMaterials($materialIds);

            foreach ($rows->groupBy('inventory_batch_id') as $batchId => $group) {
                $returned = BatchAdjustment::where('type', 'void_return')->where('sale_item_id', $item->id)
                    ->where('source_batch_id', $batchId)->sum('quantity');
                $net = $this->q($group->sum('quantity_consumed') - $returned);
                if ($net <= self::EPSILON) {
                    continue;
                }

                $source = $this->lockBatch($batchId);
                $target = $this->currentOpenBatch($source->material_id) ?? $this->reopen($source);

                $this->applyAdjustment($target, 'void_return', $net, $reason ?: "Returned from voided job: {$item->item_name}", $userId, [
                    'sale_item_id' => $item->id,
                    'source_batch_id' => $source->id,
                ]);
            }

            MaterialOffcut::where('used_for_sale_item_id', $item->id)->where('status', 'used')
                ->update(['status' => 'available', 'used_for_sale_item_id' => null, 'used_at' => null]);

            foreach (Material::whereIn('id', $materialIds)->get() as $material) {
                $this->afterStockChange($material);
            }
        });
    }

    // =====================================================================
    // 4. Wastage, breakage, counts, offcuts
    // =====================================================================

    /** Sir Jay's example: pack of 500, 5 broken -> recordLoss($batch, 'damaged', 5, 'Broken in delivery'). */
    public function recordLoss(InventoryBatch $batch, string $type, float $quantity, string $reason, ?int $userId, ?int $saleItemId = null): BatchAdjustment
    {
        if (!in_array($type, BatchAdjustment::STAFF_TYPES, true)) {
            throw new InventoryException('Loss type must be damaged or wasted.');
        }
        $qty = $this->q($quantity);
        if ($qty <= 0) {
            throw new InventoryException('Enter how much was lost.');
        }
        if (trim($reason) === '') {
            throw new InventoryException('Give a reason, e.g. "cracked while cutting".');
        }

        return DB::transaction(function () use ($batch, $type, $qty, $reason, $userId, $saleItemId) {
            $material = $this->lockMaterial($batch->material_id);
            $batch = $this->lockBatch($batch->id);

            if ($batch->status === 'closed') {
                throw new InventoryException("{$batch->batch_number} is already closed. Record the loss on the active batch.");
            }
            if ($qty - $batch->remaining_quantity > self::EPSILON) {
                throw new InventoryException("{$batch->batch_number} only has {$this->fmt($batch->remaining_quantity)} {$material->unit} left.");
            }

            $adjustment = $this->applyAdjustment($batch, $type, -$qty, trim($reason), $userId, ['sale_item_id' => $saleItemId]);
            if ($batch->remaining_quantity <= self::EPSILON) {
                $this->markClosed($batch, 'empty', $userId);
            }
            $this->afterStockChange($material);

            return $adjustment;
        });
    }

    /** Physical count. Logs the difference as a count_correction. Null when nothing changed. */
    public function recordCount(InventoryBatch $batch, float $counted, ?int $userId, ?string $reason = null): ?BatchAdjustment
    {
        $counted = $this->q($counted);
        if ($counted < 0) {
            throw new InventoryException('A count cannot be negative.');
        }

        return DB::transaction(function () use ($batch, $counted, $userId, $reason) {
            $material = $this->lockMaterial($batch->material_id);
            $batch = $this->lockBatch($batch->id);

            if ($batch->status === 'closed') {
                throw new InventoryException("{$batch->batch_number} is closed. Count the active batch instead.");
            }

            $system = $this->q($batch->remaining_quantity);
            $difference = $this->q($counted - $system);
            if (abs($difference) <= self::EPSILON) {
                return null;
            }

            $adjustment = $this->applyAdjustment($batch, 'count_correction', $difference,
                $reason ?: "Physical count: counted {$this->fmt($counted)}, system had {$this->fmt($system)}.", $userId);
            if ($batch->remaining_quantity <= self::EPSILON) {
                $this->markClosed($batch, 'empty', $userId);
            }
            $this->afterStockChange($material);

            return $adjustment;
        });
    }

    public function discardOffcut(MaterialOffcut $offcut): MaterialOffcut
    {
        return DB::transaction(function () use ($offcut) {
            $offcut = MaterialOffcut::whereKey($offcut->id)->lockForUpdate()->firstOrFail();
            if ($offcut->status !== 'available') {
                throw new InventoryException("Offcut #{$offcut->id} is already {$offcut->status}.");
            }
            $offcut->update(['status' => 'discarded', 'discarded_at' => now()]);

            return $offcut;
        });
    }

    // =====================================================================
    // 5. Reading and checking
    // =====================================================================

    public function stockSummary(Material $material): array
    {
        return $this->alerts->summary($material);
    }

    /** Re-sync the stock total and alerts, e.g. after the reorder point was edited or a material archived. */
    public function refreshMaterial(Material $material): void
    {
        DB::transaction(function () use ($material) {
            $this->afterStockChange($this->lockMaterial($material->id));
        });
    }

    /**
     * Checks that every batch balances and every material total matches its batches.
     * Returns a list of problems (empty = all good).
     */
    public function auditBatches(?int $materialId = null): array
    {
        $problems = [];

        $rows = DB::select('SELECT b.batch_number, b.remaining_quantity,
                    ROUND(b.opening_quantity - COALESCE(c.used, 0) + COALESCE(a.adjusted, 0), 3) AS expected
                FROM inventory_batches b
                LEFT JOIN (SELECT inventory_batch_id, SUM(quantity_consumed) AS used
                           FROM batch_consumptions GROUP BY inventory_batch_id) c ON c.inventory_batch_id = b.id
                LEFT JOIN (SELECT inventory_batch_id, SUM(quantity) AS adjusted
                           FROM batch_adjustments GROUP BY inventory_batch_id) a ON a.inventory_batch_id = b.id
                WHERE (? IS NULL OR b.material_id = ?)', [$materialId, $materialId]);
        foreach ($rows as $row) {
            if (abs($row->expected - $row->remaining_quantity) > self::EPSILON) {
                $problems[] = "{$row->batch_number}: the log adds up to {$this->fmt($row->expected)}, but the batch shows {$this->fmt($row->remaining_quantity)}.";
            }
        }

        $materials = DB::select("SELECT m.name, m.stock_quantity, COALESCE(SUM(b.remaining_quantity), 0) AS batch_total
                FROM materials m
                LEFT JOIN inventory_batches b ON b.material_id = m.id AND b.status IN ('unopened', 'open')
                WHERE (? IS NULL OR m.id = ?)
                GROUP BY m.id, m.name, m.stock_quantity", [$materialId, $materialId]);
        foreach ($materials as $row) {
            if (abs($row->stock_quantity - $row->batch_total) > self::EPSILON) {
                $problems[] = "{$row->name}: stock shows {$this->fmt($row->stock_quantity)}, but its batches hold {$this->fmt($row->batch_total)}.";
            }
        }

        return $problems;
    }

    /** Material code used in batch numbers. Suggested from the name the first time it's needed. */
    public function ensureCode(Material $material): string
    {
        if ($material->code) {
            return $material->code;
        }

        $base = Material::suggestCode($material->name);
        $code = $base;
        for ($i = 2; Material::where('code', $code)->where('id', '<>', $material->id)->exists(); $i++) {
            $code = substr($base, 0, 10 - strlen((string) $i)) . $i;
        }

        $material->code = $code;
        $material->save();

        return $material->code;
    }

    // =====================================================================
    // Internals
    // =====================================================================

    private function createBatch(Material $material, Carbon $date, array $attributes): InventoryBatch
    {
        return InventoryBatch::create($attributes + [
            'material_id' => $material->id,
            'batch_number' => $this->nextBatchNumber($material, $date),
            'status' => 'unopened',
            'received_at' => $date->toDateString(),
        ]);
    }

    // {MONTH}-{YEAR}-{CODE}-{N}: SEPT-2026-CB-1. N restarts every month. Material row is locked by the caller.
    private function nextBatchNumber(Material $material, Carbon $date): string
    {
        $labels = config('inventory.month_labels', []);
        $month = $labels[(int) $date->format('n')] ?? strtoupper($date->format('M'));
        $prefix = $month . '-' . $date->format('Y') . '-' . $this->ensureCode($material) . '-';

        $last = InventoryBatch::where('batch_number', 'like', $prefix . '%')->pluck('batch_number')
            ->map(function ($number) use ($prefix) { return substr($number, strlen($prefix)); })
            ->filter(function ($suffix) { return ctype_digit($suffix); })
            ->map(function ($suffix) { return (int) $suffix; })
            ->max();

        return $prefix . (($last ?? 0) + 1);
    }

    // What the counter can use right now: the active batch plus sealed packs in the store.
    private function storeAvailable(int $materialId): float
    {
        return $this->q(InventoryBatch::where('material_id', $materialId)
            ->where(function ($q) {
                $q->where('status', 'open')
                    ->orWhere(function ($q) {
                        $q->where('status', 'unopened')->where('location', 'store');
                    });
            })
            ->sum('remaining_quantity'));
    }

    private function autoOpenNext(Material $material, ?int $userId): InventoryBatch
    {
        $next = InventoryBatch::where('material_id', $material->id)
            ->where('location', 'store')->where('status', 'unopened')
            ->orderBy('received_at')->orderBy('id')
            ->lockForUpdate()->first();

        if (!$next) {
            throw new InventoryException("No sealed pack of {$material->name} left in the store.");
        }

        $this->markOpened($next, 'auto', $userId);

        return $next;
    }

    private function currentOpenBatch(int $materialId): ?InventoryBatch
    {
        return InventoryBatch::where('material_id', $materialId)->where('status', 'open')->lockForUpdate()->first();
    }

    // A voided job came back but no batch is open: the batch it came from becomes active again.
    private function reopen(InventoryBatch $batch): InventoryBatch
    {
        $batch->forceFill(['status' => 'open', 'closed_at' => null, 'close_reason' => null, 'closed_by' => null])->save();

        return $batch;
    }

    private function markOpened(InventoryBatch $batch, string $method, ?int $userId): void
    {
        $batch->forceFill([
            'status' => 'open',
            'opened_at' => now(),
            'open_method' => $method,
            'opened_by' => $userId,
        ])->save();
    }

    private function markClosed(InventoryBatch $batch, string $reason, ?int $userId): void
    {
        if (abs($this->q($batch->remaining_quantity)) > self::EPSILON) {
            throw new \LogicException("{$batch->batch_number} still has stock and can't be closed.");
        }

        $batch->forceFill([
            'remaining_quantity' => 0,
            'status' => 'closed',
            'closed_at' => now(),
            'close_reason' => $reason,
            'closed_by' => $userId,
        ])->save();
    }

    // Leftover on a batch being closed must become offcuts and/or a write-off with a reason.
    private function settleLeftover(InventoryBatch $batch, array $leftover, ?int $userId): void
    {
        $remaining = $this->q($batch->remaining_quantity);
        if ($remaining <= self::EPSILON) {
            return;
        }

        $unit = $batch->material->unit;
        $offcuts = $leftover['offcuts'] ?? [];
        $reason = trim((string) ($leftover['write_off_reason'] ?? ''));

        $kept = 0.0;
        foreach ($offcuts as $offcut) {
            $q = $this->q($offcut['quantity'] ?? 0);
            if ($q <= 0) {
                throw new InventoryException('Each offcut needs a quantity more than zero.');
            }
            $kept = $this->q($kept + $q);
        }
        if ($kept - $remaining > self::EPSILON) {
            throw new InventoryException("The offcuts add up to {$this->fmt($kept)} {$unit}, but {$batch->batch_number} only has {$this->fmt($remaining)} left.");
        }

        $rest = $this->q($remaining - $kept);
        if ($rest > self::EPSILON && $reason === '') {
            throw new InventoryException("{$batch->batch_number} still has {$this->fmt($rest)} {$unit} left. Save it as offcuts or give a reason to write it off.");
        }

        foreach ($offcuts as $offcut) {
            $q = $this->q($offcut['quantity']);
            $w = isset($offcut['width']) && $offcut['width'] !== '' ? (float) $offcut['width'] : null;
            $h = isset($offcut['height']) && $offcut['height'] !== '' ? (float) $offcut['height'] : null;
            $label = trim((string) ($offcut['label'] ?? ''));
            if ($label === '') {
                $label = $w && $h ? "{$this->fmt($w)} x {$this->fmt($h)} piece" : "{$this->fmt($q)} {$unit} piece";
            }

            $saved = MaterialOffcut::create([
                'material_id' => $batch->material_id,
                'source_batch_id' => $batch->id,
                'label' => Str::limit($label, 97),
                'width' => $w,
                'height' => $h,
                'quantity' => $q,
                'status' => 'available',
                'created_by' => $userId,
            ]);
            $this->applyAdjustment($batch, 'to_offcut', -$q, "Kept as offcut: {$saved->label}", $userId, ['material_offcut_id' => $saved->id]);
        }

        if ($rest > self::EPSILON) {
            $this->applyAdjustment($batch, 'write_off', -$rest, $reason, $userId);
        }
    }

    private function applyAdjustment(InventoryBatch $batch, string $type, float $signedQty, ?string $reason, ?int $userId, array $links = []): BatchAdjustment
    {
        $before = $this->q($batch->remaining_quantity);
        $after = $this->q($before + $signedQty);
        if ($after < -self::EPSILON) {
            throw new InventoryException("{$batch->batch_number} only has {$this->fmt($before)} left.");
        }
        $after = max($after, 0.0);

        $batch->remaining_quantity = $after;
        $batch->save();

        return BatchAdjustment::create($links + [
            'inventory_batch_id' => $batch->id,
            'type' => $type,
            'quantity' => $this->q($signedQty),
            'quantity_before' => $before,
            'quantity_after' => $after,
            'reason' => $reason !== null ? Str::limit($reason, 250) : null,
            'user_id' => $userId,
            'created_at' => now(),
        ]);
    }

    // How much of one BOM line a job line needs.
    private function bomQuantity(Material $material, SaleItem $item): float
    {
        $perUnit = (float) $material->pivot->quantity_per_unit;
        $qty = (int) $item->quantity;
        $type = $material->pivot->consumption_type ?? 'fixed';

        if ($type === 'per_area') {
            if ((float) $item->width <= 0 || (float) $item->height <= 0) {
                throw new InventoryException("Enter the width and height for {$item->item_name}. It is cut from {$material->name}.");
            }
            return $this->q($perUnit * $item->width * $item->height * $qty);
        }

        if ($type === 'per_length') {
            if ((float) $item->height <= 0) {
                throw new InventoryException("Enter the length (height) for {$item->item_name}. It is cut from {$material->name}.");
            }
            return $this->q($perUnit * $item->height * $qty);
        }

        return $this->q($perUnit * $qty);
    }

    private function claimableOffcuts(array $ids, Collection $lines): Collection
    {
        if (!$ids) {
            return collect();
        }

        $offcuts = MaterialOffcut::whereIn('id', $ids)->lockForUpdate()->get()->keyBy('id');
        foreach ($ids as $id) {
            $offcut = $offcuts->get($id);
            if (!$offcut) {
                throw new InventoryException("Offcut #{$id} was not found.");
            }
            if ($offcut->status !== 'available') {
                throw new InventoryException("Offcut #{$id} ({$offcut->label}) is already {$offcut->status}.");
            }
            if (!$lines->contains('id', $offcut->material_id)) {
                throw new InventoryException("Offcut #{$id} ({$offcut->label}) is not a material this item uses.");
            }
        }
        if ($offcuts->pluck('material_id')->duplicates()->isNotEmpty()) {
            throw new InventoryException('Pick only one offcut per material.');
        }

        return $offcuts->keyBy('material_id');
    }

    private function assertOffcutFits(MaterialOffcut $offcut, float $need, SaleItem $item, Material $material): void
    {
        if ($this->q($offcut->quantity) + self::EPSILON < $need) {
            throw new InventoryException("Offcut #{$offcut->id} ({$offcut->label}) is too small: the job needs {$this->fmt($need)} {$material->unit}, the offcut has {$this->fmt($offcut->quantity)}.");
        }

        $w = (float) $offcut->width;
        $h = (float) $offcut->height;
        $jobW = (float) $item->width;
        $jobH = (float) $item->height;
        if ($w > 0 && $h > 0 && $jobW > 0 && $jobH > 0 && !(($w >= $jobW && $h >= $jobH) || ($w >= $jobH && $h >= $jobW))) {
            throw new InventoryException("Offcut #{$offcut->id} ({$offcut->label}) can't fit a {$this->fmt($jobW)} x {$this->fmt($jobH)} cut.");
        }
    }

    // Splits the line price across its materials by cost, for the per-batch revenue report.
    private function splitRevenue(float $subtotal, Collection $lines, array $needs): array
    {
        $weights = [];
        foreach ($lines as $i => $material) {
            $weights[$i] = $needs[$i] > 0 ? $needs[$i] * (float) $material->cost_per_unit : 0.0;
        }
        if (array_sum($weights) <= 0) {
            foreach ($weights as $i => $weight) {
                $weights[$i] = $needs[$i] > 0 ? 1.0 : 0.0;   // no costs entered: split evenly
            }
        }

        $total = array_sum($weights);
        $keys = array_keys(array_filter($weights, function ($w) { return $w > 0; }));
        $left = round($subtotal, 2);
        $shares = [];
        foreach ($keys as $n => $i) {
            $shares[$i] = $n === count($keys) - 1 ? $left : round($subtotal * $weights[$i] / $total, 2);
            $left = round($left - $shares[$i], 2);
        }

        return $shares;
    }

    private function afterStockChange(Material $material): void
    {
        $material->stock_quantity = $this->q(InventoryBatch::where('material_id', $material->id)->usable()->sum('remaining_quantity'));
        $material->save();
        $this->alerts->evaluate($material);
    }

    private function lockMaterial(int $id): Material
    {
        return Material::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function lockMaterials(array $ids): void
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        if ($ids) {
            Material::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        }
    }

    private function lockBatch(int $id): InventoryBatch
    {
        return InventoryBatch::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function assertLocation(string $location): void
    {
        if (!in_array($location, ['store', 'warehouse'], true)) {
            throw new InventoryException('Location must be store or warehouse.');
        }
    }

    private function q($value): float
    {
        return round((float) $value, 3);
    }

    private function fmt($value): string
    {
        return StockAlertService::formatQty($value);
    }
}
