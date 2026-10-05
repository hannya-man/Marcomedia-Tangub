<?php
namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Inventory\BatchInventoryService;
use App\Services\Inventory\InventoryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class PurchaseOrderController extends Controller
{
    // Owner records what was ordered from CDO or Pagadian. Bundles = one line with several packs.
    public function store(Request $request, BatchInventoryService $inventory)
    {
        $data = $request->validate([
            'supplier_id' => ['required', Rule::exists('suppliers', 'id')->whereNull('archived_at')],
            'expected_at' => 'nullable|date',
            'notes' => 'nullable|string|max:255',
            'items' => 'required|array|min:1',
            'items.*.material_id' => ['required', 'distinct', Rule::exists('materials', 'id')->whereNull('archived_at')],
            'items.*.packs' => 'required|integer|min:1|max:1000',
            'items.*.qty_per_pack' => 'required|numeric|min:0.001',
            'items.*.cost_per_pack' => 'nullable|numeric|min:0',
        ]);

        try {
            $po = $inventory->createPurchaseOrder(
                Supplier::findOrFail($data['supplier_id']), $data['items'], Auth::id(),
                $data['notes'] ?? null, $data['expected_at'] ?? null
            );
        } catch (InventoryException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$po->po_number} saved.");
    }

    // Delivery arrives: each pack becomes a sealed batch in the store or warehouse.
    public function receive(Request $request, PurchaseOrderItem $item, BatchInventoryService $inventory)
    {
        $data = $request->validate([
            'packs' => 'required|integer|min:1',
            'location' => 'required|in:store,warehouse',
            'received_at' => 'nullable|date|before_or_equal:today',
        ]);

        try {
            $batches = $inventory->receive($item, (int) $data['packs'], $data['location'], Auth::id(), $data['received_at'] ?? null);
        } catch (InventoryException $e) {
            return back()->with('error', $e->getMessage());
        }

        $first = $batches->first()->batch_number;
        $last = $batches->last()->batch_number;

        return back()->with('success', $batches->count() === 1
            ? "Received as {$first}."
            : "Received {$batches->count()} packs: {$first} to {$last}.");
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder, BatchInventoryService $inventory)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:150']);

        try {
            $inventory->cancelPurchaseOrder($purchaseOrder, $data['reason'] ?? null);
        } catch (InventoryException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$purchaseOrder->po_number} cancelled.");
    }
}
