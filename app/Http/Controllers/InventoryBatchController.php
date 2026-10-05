<?php
namespace App\Http\Controllers;

use App\Models\InventoryBatch;
use App\Models\Material;
use App\Models\MaterialOffcut;
use App\Services\Inventory\BatchInventoryService;
use App\Services\Inventory\InventoryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class InventoryBatchController extends Controller
{
    // Opening stock at go-live only (admin). Deliveries after go-live use Receive on a PO.
    public function store(Request $request, BatchInventoryService $inventory)
    {
        $data = $request->validate([
            'material_id' => ['required', Rule::exists('materials', 'id')->whereNull('archived_at')],
            'location' => 'required|in:store,warehouse',
            'quantity' => 'required|numeric|min:0.001',
            'cost_per_unit' => 'nullable|numeric|min:0',
            'received_at' => 'nullable|date|before_or_equal:today',
        ]);

        return $this->attempt(function () use ($inventory, $data) {
            $batch = $inventory->recordOpeningStock(
                Material::findOrFail($data['material_id']), (float) $data['quantity'], $data['location'],
                Auth::id(), isset($data['cost_per_unit']) ? (float) $data['cost_per_unit'] : null, $data['received_at'] ?? null
            );
            return "Opening stock saved as {$batch->batch_number} ({$data['location']}).";
        });
    }

    // Sir Jay's rule: opening this batch closes the current one. Leftover must be explained.
    public function open(Request $request, InventoryBatch $batch, BatchInventoryService $inventory)
    {
        $leftover = $request->validate($this->leftoverRules());

        return $this->attempt(function () use ($inventory, $batch, $leftover) {
            $inventory->openBatch($batch, Auth::id(), $leftover);
            return "{$batch->batch_number} is now the active batch.";
        });
    }

    public function close(Request $request, InventoryBatch $batch, BatchInventoryService $inventory)
    {
        $leftover = $request->validate($this->leftoverRules());

        return $this->attempt(function () use ($inventory, $batch, $leftover) {
            $inventory->closeBatch($batch, Auth::id(), $leftover);
            return "{$batch->batch_number} closed.";
        });
    }

    public function transfer(InventoryBatch $batch, BatchInventoryService $inventory)
    {
        return $this->attempt(function () use ($inventory, $batch) {
            $inventory->transferToStore($batch);
            return "{$batch->batch_number} moved to the store.";
        });
    }

    // Breakage, miscuts, misprints. Example: 5 of 500 mug blanks broken.
    public function recordLoss(Request $request, InventoryBatch $batch, BatchInventoryService $inventory)
    {
        $data = $request->validate([
            'type' => 'required|in:damaged,wasted',
            'quantity' => 'required|numeric|min:0.001',
            'reason' => 'required|string|max:255',
            'sale_item_id' => 'nullable|exists:sale_items,id',
        ]);

        return $this->attempt(function () use ($inventory, $batch, $data) {
            $inventory->recordLoss($batch, $data['type'], (float) $data['quantity'], $data['reason'], Auth::id(),
                isset($data['sale_item_id']) ? (int) $data['sale_item_id'] : null);
            return "Loss recorded on {$batch->batch_number}.";
        });
    }

    public function recordCount(Request $request, InventoryBatch $batch, BatchInventoryService $inventory)
    {
        $data = $request->validate([
            'counted' => 'required|numeric|min:0',
            'reason' => 'nullable|string|max:255',
        ]);

        return $this->attempt(function () use ($inventory, $batch, $data) {
            $adjustment = $inventory->recordCount($batch, (float) $data['counted'], Auth::id(), $data['reason'] ?? null);
            return $adjustment
                ? "Count saved on {$batch->batch_number}. Difference: " . ($adjustment->quantity > 0 ? '+' : '') . (float) $adjustment->quantity . '.'
                : "Count matches the system. Nothing changed.";
        });
    }

    public function discardOffcut(MaterialOffcut $offcut, BatchInventoryService $inventory)
    {
        return $this->attempt(function () use ($inventory, $offcut) {
            $inventory->discardOffcut($offcut);
            return "Offcut {$offcut->label} discarded.";
        });
    }

    private function attempt(callable $action)
    {
        try {
            return back()->with('success', $action());
        } catch (InventoryException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
    }

    private function leftoverRules(): array
    {
        return [
            'offcuts' => 'nullable|array|max:20',
            'offcuts.*.label' => 'nullable|string|max:100',
            'offcuts.*.quantity' => 'required|numeric|min:0.001',
            'offcuts.*.width' => 'nullable|numeric|min:0',
            'offcuts.*.height' => 'nullable|numeric|min:0',
            'write_off_reason' => 'nullable|string|max:255',
        ];
    }
}
