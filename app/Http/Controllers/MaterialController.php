<?php
namespace App\Http\Controllers;

use App\Models\Material;
use App\Services\Inventory\BatchInventoryService;
use App\Services\Inventory\InventoryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

// Actions on one material. Materials are listed and added on the Continuous and
// Discrete pages (StockController), whose cards call restock / archive here.
class MaterialController extends Controller
{
    // Reorder settings. Alerts are re-checked right away, so a new reorder point takes effect now.
    public function update(Request $request, Material $material, BatchInventoryService $inventory)
    {
        $validated = $request->validate($this->rules($material));
        if (!isset($validated['low_stock_threshold'])) {
            unset($validated['low_stock_threshold']);
        }

        $material->update($validated);
        $inventory->refreshMaterial($material);

        return back()->with('success', "{$material->name} updated.");
    }

    // Restock without a purchase order. The new stock still becomes its own sealed batch
    // with a batch number (e.g. OCT-2026-LF-3), so nothing is typed straight into stock.
    public function adjustStock(Request $request, Material $material, BatchInventoryService $inventory)
    {
        if ($material->archived_at) {
            return back()->with('error', "{$material->name} is archived. Restore it first.");
        }

        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.001',
            'location' => 'nullable|in:store,warehouse',
            'cost_per_unit' => 'nullable|numeric|min:0',
        ]);

        try {
            $batch = $inventory->recordOpeningStock(
                $material,
                (float) $validated['quantity'],
                $validated['location'] ?? 'store',
                Auth::id(),
                isset($validated['cost_per_unit']) ? (float) $validated['cost_per_unit'] : null
            );
        } catch (InventoryException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Restocked {$material->name}: batch {$batch->batch_number}.");
    }

    // "Delete" archives instead of removing the record. Its stock alerts resolve.
    public function destroy(Material $material, BatchInventoryService $inventory)
    {
        $material->update(['archived_at' => now()]);
        $inventory->refreshMaterial($material);

        return back()->with('success', "'{$material->name}' archived.");
    }

    public function restore(Material $material, BatchInventoryService $inventory)
    {
        $material->update(['archived_at' => null]);
        $inventory->refreshMaterial($material);

        return back()->with('success', "'{$material->name}' restored.");
    }

    // Every archived material. "from" is the page that opened it, so Back returns there.
    public function archive(Request $request)
    {
        $archivedMaterials = Material::whereNotNull('archived_at')->orderByDesc('archived_at')->get();
        $from = $request->query('from') === 'discrete' ? 'discrete' : 'continuous';

        return view('inventory.materials-archive', compact('archivedMaterials', 'from'));
    }

    private function rules(?Material $material = null): array
    {
        return [
            'material_category_id' => 'nullable|exists:material_categories,id',
            'name' => 'required|string|max:150',
            'code' => ['nullable', 'alpha_num', 'max:10', Rule::unique('materials', 'code')->ignore($material?->id)],
            'unit' => 'required|string|max:20',
            'pack_size' => 'nullable|numeric|min:0',
            'low_stock_threshold' => 'nullable|numeric|min:0',
            'reorder_packs' => 'nullable|integer|min:1|max:1000',
            'default_supplier_id' => ['nullable', Rule::exists('suppliers', 'id')->whereNull('archived_at')],
            'cost_per_unit' => 'nullable|numeric|min:0',
        ];
    }
}
