<?php
namespace App\Http\Controllers;

use App\Models\SaleItem;
use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SaleReturnController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'sale_item_id' => 'required|exists:sale_items,id',
            'quantity' => 'required|integer|min:1',
            'condition' => 'required|in:good,damaged',
            'reason' => 'nullable|string|max:255',
        ]);

        $saleItem = SaleItem::findOrFail($validated['sale_item_id']);
        $variant = $saleItem->variant;

        if (!$variant) {
            return back()->with('error', 'This item has no linked stock variant — cannot process a stock return for it.');
        }

        // Good condition goes back to sellable stock. Damaged goes
        // straight into damaged_quantity — it never becomes sellable again
        // without a separate manual correction.
        if ($validated['condition'] === 'good') {
            $variant->adjustStock($validated['quantity'], 'return', 'return', $saleItem->id, $validated['reason'] ?? null, Auth::id());
        } else {
            $variant->increment('damaged_quantity', $validated['quantity']);
        }

        SaleReturn::create([
            'sale_item_id' => $saleItem->id,
            'quantity' => $validated['quantity'],
            'condition' => $validated['condition'],
            'reason' => $validated['reason'] ?? null,
            'processed_by' => Auth::id(),
            'created_at' => now(),
        ]);

        return back()->with('success', 'Return processed.');
    }
}
