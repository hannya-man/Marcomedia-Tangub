<?php
namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Services\Inventory\BackorderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

// An item a customer brings back (owner only), found by the sale's invoice number. A reason is the
// proof for every return. A good item goes back on sale; a damaged one is kept apart, never sold.
class SaleReturnController extends Controller
{
    public function index(Request $request)
    {
        $sale = null;
        if ($request->filled('invoice')) {
            $sale = Sale::with('items.product', 'customer')->where('invoice_number', trim($request->query('invoice')))->first();
        }
        $returned = $sale ? SaleReturn::whereIn('sale_item_id', $sale->items->pluck('id'))->groupBy('sale_item_id')
            ->selectRaw('sale_item_id, SUM(quantity) as quantity')->pluck('quantity', 'sale_item_id') : collect();
        $recent = SaleReturn::with('saleItem.sale', 'processedBy')->latest('created_at')->latest('id')->limit(20)->get();

        return view('returns.index', compact('sale', 'returned', 'recent'));
    }

    public function store(Request $request, BackorderService $backorders)
    {
        $validated = $request->validate([
            'sale_item_id' => 'required|exists:sale_items,id',
            'quantity' => 'required|integer|min:1',
            'condition' => 'required|in:good,damaged',
            'reason' => 'required|string|min:3|max:255',
        ], [
            'reason.required' => 'Write why it came back (or the slip number). It is the proof for this return.',
            'reason.min' => 'Write why it came back (or the slip number). It is the proof for this return.',
        ]);

        $saleItem = SaleItem::with('sale', 'variant', 'product')->findOrFail($validated['sale_item_id']);
        $sale = $saleItem->sale;
        $quantity = (int) $validated['quantity'];

        // A voided sale already put its stock back; an item still on order was never handed over.
        if (! in_array($sale->status, ['pending', 'processing', 'completed'], true)) {
            return back()->with('error', "{$sale->invoice_number} is " . ucfirst($sale->status) . ': nothing on it can be returned.');
        }
        if ($saleItem->isOnOrder()) {
            return back()->with('error', "{$saleItem->item_name} on {$sale->invoice_number} is still on order: it was never handed over, so it can't come back.");
        }
        $left = $saleItem->quantity - (int) SaleReturn::where('sale_item_id', $saleItem->id)->sum('quantity');
        if ($quantity > $left) {
            return back()->with('error', $left > 0
                ? "Only {$left} of {$saleItem->item_name} on {$sale->invoice_number} can still be returned."
                : "All of {$saleItem->item_name} on {$sale->invoice_number} was already returned.");
        }

        // Only an item kept in stock goes back to a count. A made-to-order one has no stock to return
        // to: the return is still kept.
        $variant = $saleItem->product?->track_inventory ? $saleItem->variant : null;
        DB::transaction(function () use ($validated, $saleItem, $variant, $quantity) {
            if ($variant) {
                // On hand again either way. A damaged one is also marked damaged, so it never sells.
                $variant->adjustStock($quantity, 'return', 'return', $saleItem->id, $validated['reason'], Auth::id());
                if ($validated['condition'] === 'damaged') {
                    $variant->increment('damaged_quantity', $quantity);
                }
            }
            SaleReturn::create([
                'sale_item_id' => $saleItem->id,
                'quantity' => $quantity,
                'condition' => $validated['condition'],
                'reason' => $validated['reason'],
                'processed_by' => Auth::id(),
                'created_at' => now(),
            ]);
        });

        $what = "{$quantity} x {$saleItem->item_name}" . ($saleItem->variant_name ? " ({$saleItem->variant_name})" : '') . " from {$sale->invoice_number}";
        $where = ! $variant ? ' Made-to-order items don\'t go back to stock.'
            : ($validated['condition'] === 'good' ? ' It\'s back in stock.' . $backorders->fillAfterRestock(Auth::id()) : ' Kept apart as damaged: it won\'t be sold.');

        return back()->with('success', "Return accepted: {$what}, {$validated['condition']}.{$where}");
    }
}
