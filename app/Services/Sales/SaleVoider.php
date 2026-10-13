<?php
namespace App\Services\Sales;

use App\Models\ApprovalRequest;
use App\Models\Sale;
use App\Models\User;
use App\Services\Inventory\BackorderService;
use App\Services\Inventory\BatchInventoryService;
use Illuminate\Support\Facades\DB;

/**
 * Voiding a sale: by an owner from Sales, or a cashier's request an owner approved (Approvals).
 * What the sale took comes back (its materials, a stocked item's count), and what came back goes
 * to items sold while out of stock.
 */
class SaleVoider
{
    public function __construct(private BatchInventoryService $inventory, private BackorderService $backorders)
    {
    }

    // Only a sale still open can be voided. A completed one is a return (owner only).
    public function canVoid(Sale $sale): bool
    {
        return in_array($sale->status, ['pending', 'processing'], true);
    }

    // Returns the note about items on order that took what came back ('' if none).
    public function void(Sale $sale, User $by): string
    {
        DB::transaction(function () use ($sale, $by) {
            foreach ($sale->items as $item) {
                // Puts back any materials this line took from a batch. Safe to call twice. A line
                // still on order took nothing.
                $this->inventory->returnSaleItem($item, $by->id, "Voided sale {$sale->invoice_number}");

                // A stocked item's count comes back, if the sale took it. Made-to-order items
                // have no count: their materials came back above.
                if ($item->variant && $item->product?->track_inventory && ! $item->isOnOrder()) {
                    $item->variant->adjustStock(
                        $item->quantity,
                        'void',
                        'sale_void',
                        $sale->id,
                        "Voided sale {$sale->invoice_number}",
                        $by->id
                    );
                }
            }
            $sale->update(['status' => 'voided']);

            // A cashier's request to void it is answered: it's done.
            ApprovalRequest::pending()->where('type', 'sale.void')->where('sale_id', $sale->id)
                ->get()->each(fn ($request) => $request->decide('approved', $by));
        });

        // What came back can go to items on order.
        return $this->backorders->fillAfterRestock($by->id);
    }
}
