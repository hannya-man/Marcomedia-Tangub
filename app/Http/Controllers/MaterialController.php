<?php
namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\Supplier;
use App\Services\Inventory\BatchInventoryService;
use App\Services\Inventory\InventoryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MaterialController extends Controller
{
    public function index()
    {
        // Variants show which finished sizes each material makes. activeBatch shows the pack in use.
        $materials = Material::with(['variants.product', 'activeBatch', 'defaultSupplier'])
            ->whereNull('archived_at')
            ->withCount('variants')
            ->orderBy('name')->get();
        $archivedCount = Material::whereNotNull('archived_at')->count();
        $suppliers = Supplier::active()->orderBy('name')->get();

        return view('inventory.materials', compact('materials', 'archivedCount', 'suppliers'));
    }

    // New material. Stock already on the shelf becomes its first batch instead of a typed-in number.
    // The old form field "stock_quantity" still works and is treated as opening stock.
    public function store(Request $request, BatchInventoryService $inventory)
    {
        $validated = $request->validate($this->rules() + [
            'opening_stock' => 'nullable|numeric|min:0',
            'stock_quantity' => 'nullable|numeric|min:0',
            'opening_location' => 'nullable|in:store,warehouse',
        ]);

        $opening = (float) ($validated['opening_stock'] ?? $validated['stock_quantity'] ?? 0);
        $fields = array_filter(
            collect($validated)->except(['opening_stock', 'stock_quantity', 'opening_location'])->all(),
            function ($value) { return $value !== null; }
        );

        try {
            DB::transaction(function () use ($inventory, $fields, $opening, $validated) {
                $material = Material::create($fields + ['stock_quantity' => 0]);
                $inventory->ensureCode($material);

                if ($opening > 0) {
                    $inventory->recordOpeningStock($material, $opening, $validated['opening_location'] ?? 'store', Auth::id());
                } else {
                    $inventory->refreshMaterial($material);
                }
            });
        } catch (InventoryException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('inventory.materials')->with('success', 'Material added.');
    }

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

    // The old Restock button typed a number straight into stock. Stock now only comes in
    // through Receive on a purchase order, so every unit belongs to a batch.
    public function adjustStock(Material $material)
    {
        return back()->with('error', "Restock is now done by receiving a purchase order, so the new stock gets a batch number. Use Receive on the PO for {$material->name}.");
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

    public function archive()
    {
        $archivedMaterials = Material::whereNotNull('archived_at')->orderByDesc('archived_at')->get();

        return view('inventory.materials-archive', compact('archivedMaterials'));
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
