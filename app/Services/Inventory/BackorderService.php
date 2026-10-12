<?php
namespace App\Services\Inventory;

use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SaleItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Marcomedia accepts orders even when an item is out of stock.
 *
 *   take()  At the sale, a line takes its stock: a made-to-order item's materials, or a stocked
 *           item's count. If the store is short, the sale still goes through and the line goes
 *           on order (sale_items.backordered_at), taking nothing yet.
 *   fill()  When stock comes in (a restock, a pack moved to the store, a voided sale), lines on
 *           order take it, oldest sale first. Each line is all or nothing: one that is still
 *           short stays on order. Only open sales (pending, processing) are filled.
 * A sale can't be completed while a line is on order: the item hasn't been made yet.
 */
class BackorderService
{
    public function __construct(private BatchInventoryService $inventory)
    {
    }

    // True if the line went on order. Runs inside the sale's transaction; a short line leaves
    // nothing behind.
    public function take(SaleItem $line, ?int $userId): bool
    {
        try {
            DB::transaction(function () use ($line, $userId) {
                $this->takeStock($line, $userId);
            });

            return false;
        } catch (OutOfStockException $e) {
            // The savepoint undid what the attempt saved, like the cut piece it picked.
            $line->refresh()->forceFill(['backordered_at' => now()])->save();

            return true;
        }
    }

    /**
     * Lines on order (of one sale, or all) whose stock has come in take it, oldest sale first.
     *
     * @return array{0: Collection, 1: array<int, string>} the lines filled, and why each line
     *         left on order is still short, by line id
     */
    public function fill(?int $userId, ?Sale $sale = null): array
    {
        $lines = SaleItem::onOrder()->with('sale')
            ->whereHas('sale', function ($q) { $q->whereIn('status', ['pending', 'processing']); })
            ->when($sale, function ($q) use ($sale) { $q->where('sale_id', $sale->id); })
            ->orderBy('sale_id')->orderBy('id')
            ->get();

        $filled = collect();
        $short = [];
        foreach ($lines as $line) {
            try {
                $done = DB::transaction(function () use ($line, $userId) {
                    // Read again under lock: two restocks at once can't fill the same line twice.
                    $locked = SaleItem::whereKey($line->id)->lockForUpdate()->first();
                    if (! $locked || ! $locked->isOnOrder()) {
                        return false;
                    }
                    $this->takeStock($locked, $userId);
                    $locked->forceFill(['backorder_filled_at' => now()])->save();

                    return true;
                });
                if ($done) {
                    $filled->push($line);
                }
            } catch (\RuntimeException $e) {   // InventoryException, or a stock count gone short
                $short[$line->id] = $e->getMessage();
            }
        }

        return [$filled, $short];
    }

    // After stock comes in: fill the lines on order, and say which sales got theirs ('' if none).
    public function fillAfterRestock(?int $userId): string
    {
        [$filled] = $this->fill($userId);
        if ($filled->isEmpty()) {
            return '';
        }

        $n = $filled->count();

        return " {$n} item" . ($n === 1 ? '' : 's') . ' on order took ' . ($n === 1 ? 'its' : 'their')
            . ' stock and can be made now: ' . $filled->pluck('sale.invoice_number')->unique()->implode(', ') . '.';
    }

    private function takeStock(SaleItem $line, ?int $userId): void
    {
        $product = $line->product;

        // Stocked item (Track inventory ON): its own count, stock minus damaged.
        if ($product && $product->track_inventory) {
            $variant = $line->product_variant_id
                ? ProductVariant::whereKey($line->product_variant_id)->lockForUpdate()->first()
                : null;
            if (! $variant) {
                return;
            }
            if ($variant->sellable_quantity < $line->quantity) {
                throw new OutOfStockException("Not enough {$product->name} ({$variant->variant_name}): "
                    . "{$variant->sellable_quantity} in stock, {$line->quantity} needed.");
            }
            $variant->adjustStock(-$line->quantity, 'sale', 'sale', $line->sale_id,
                'Sold via ' . ($line->sale->invoice_number ?? "sale #{$line->sale_id}"), $userId);

            return;
        }

        // Made to order: its discrete materials, or the standard pieces it's cut as. Finished goods
        // used theirs at production, and continuous materials are logged by hand (Pull for use).
        $this->inventory->consumeForSaleItem($line, $userId);
    }
}
