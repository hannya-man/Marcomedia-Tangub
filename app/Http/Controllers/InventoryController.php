<?php
namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        $query = Product::with('variants.materials', 'category')->where('status', '!=', 'archived');

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->orderBy('name')->paginate(15)->withQueryString();
        $categories = Category::whereNull('archived_at')->orderBy('name')->get();
        $archivedCount = Product::where('status', 'archived')->count();
        $materials = \App\Models\Material::whereNull('archived_at')->orderBy('name')->get();

        return view('inventory.index', compact('products', 'categories', 'archivedCount', 'materials'));
    }

    // Add Product now lives inline on the index page as a popout panel —
    // no separate create page needed anymore.

    public function store(Request $request)
    {
        // Native <select> elements submit an empty string ("") for an
        // unselected option, not null — and Laravel's `nullable` rule only
        // skips further checks when a value is exactly null, not "". Left
        // as-is, `nullable|exists:materials,id` would fail validation for
        // every material row that's intentionally left blank, silently
        // blocking the whole product from being saved. Cleaning these to
        // real null before validating is what actually fixes that.
        $variantsInput = $request->input('variants', []);
        foreach ($variantsInput as $i => $variant) {
            foreach ($variant['materials'] ?? [] as $j => $vm) {
                if (($vm['material_id'] ?? '') === '') {
                    $variantsInput[$i]['materials'][$j]['material_id'] = null;
                }
                if (($vm['quantity_per_unit'] ?? '') === '') {
                    $variantsInput[$i]['materials'][$j]['quantity_per_unit'] = null;
                }
            }
        }
        $request->merge(['variants' => $variantsInput]);

        $validated = $request->validate([
            'category_id' => ['nullable', Rule::exists('categories', 'id')->whereNull('archived_at')],
            'new_category_name' => 'nullable|string|max:100',
            'name' => 'required|string|max:150',
            'sku' => 'required|string|max:60|unique:products,sku',
            'description' => 'nullable|string',
            'base_price' => 'required|numeric|min:0',
            'has_variants' => 'boolean',
            'track_inventory' => 'boolean',
            'variants' => 'nullable|array',
            'variants.*.variant_name' => 'required_with:variants|string|max:100',
            'variants.*.sku' => 'required_with:variants|string|max:60|distinct',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.low_stock_threshold' => 'nullable|integer|min:0',
            'variants.*.materials.*.material_id' => ['nullable', Rule::exists('materials', 'id')->whereNull('archived_at')],
            'variants.*.materials.*.quantity_per_unit' => 'nullable|numeric|min:0',
        ]);

        // If they typed a new category instead of picking one, create it now.
        $categoryId = $validated['category_id'] ?? null;
        if (!empty($validated['new_category_name'])) {
            $category = Category::firstOrCreate(
                ['slug' => str($validated['new_category_name'])->slug()],
                ['name' => $validated['new_category_name']]
            );
            $categoryId = $category->id;
        }

        $product = Product::create([
            'category_id' => $categoryId,
            'name' => $validated['name'],
            'sku' => $validated['sku'],
            'description' => $validated['description'] ?? null,
            'base_price' => $validated['base_price'],
            'has_variants' => $request->boolean('has_variants'),
            'track_inventory' => $request->boolean('track_inventory', true),
            'status' => 'active',
        ]);

        // Sizes are created at 0 stock — quantity only ever enters through
        // a batch / restock transaction, never at product creation. This
        // keeps Product (the master record) and Stock (a separate,
        // batch-based concept) from getting tangled together.
        foreach ($validated['variants'] ?? [] as $v) {
            $variant = $product->variants()->create([
                'variant_name' => $v['variant_name'],
                'sku' => $v['sku'],
                'price' => $v['price'] ?? null,
                'stock_quantity' => 0,
                'low_stock_threshold' => $v['low_stock_threshold'] ?? 5,
                'reorder_point' => ($v['low_stock_threshold'] ?? 5) * 2,
                'status' => 'active',
            ]);

            // Attach every material this size actually uses — a shirt can
            // (and usually does) draw from more than one at once.
            foreach ($v['materials'] ?? [] as $vm) {
                if (!empty($vm['material_id']) && !empty($vm['quantity_per_unit'])) {
                    $variant->materials()->attach($vm['material_id'], [
                        'quantity_per_unit' => $vm['quantity_per_unit'],
                    ]);
                }
            }
        }

        return redirect()->route('inventory.index')->with('success', "Product '{$product->name}' created.");
    }

    // Restock or manually adjust a single variant
    public function adjustStock(Request $request, ProductVariant $variant)
    {
        $validated = $request->validate([
            'type' => 'required|in:stock_in,adjustment',
            'quantity' => 'required|integer|min:1',
            'remarks' => 'nullable|string|max:255',
        ]);

        $signedQty = $validated['type'] === 'stock_in' ? $validated['quantity'] : $validated['quantity'];
        // For manual "adjustment" that means correcting DOWN, flip sign via a separate field in real UI;
        // kept simple here: stock_in always adds.
        if ($request->input('direction') === 'out') {
            $signedQty = -$validated['quantity'];
        }

        $variant->adjustStock(
            $signedQty,
            $validated['type'],
            'manual',
            null,
            $validated['remarks'] ?? null,
            Auth::id()
        );

        return back()->with('success', "Stock updated for {$variant->variant_name}.");
    }

    // Marks units as damaged — pulled out of sellable count without
    // touching stock_quantity itself, so the audit trail (how many ever
    // came in) stays intact.
    public function markDamaged(Request $request, ProductVariant $variant)
    {
        $validated = $request->validate([
            'quantity' => 'required|integer|min:1',
            'remarks' => 'nullable|string|max:255',
        ]);

        try {
            $variant->markDamaged($validated['quantity'], $validated['remarks'] ?? null, Auth::id());
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$validated['quantity']} unit(s) of {$variant->variant_name} marked damaged.");
    }

    // "Delete" archives the product instead of removing it — nothing is
    // ever permanently lost, it just leaves the main list.
    public function destroy(Product $product)
    {
        $product->update(['status' => 'archived', 'archived_at' => now()]);
        return back()->with('success', "'{$product->name}' archived.");
    }

    public function restore(Product $product)
    {
        $product->update(['status' => 'active', 'archived_at' => null]);
        return back()->with('success', "'{$product->name}' restored.");
    }

    public function archive()
    {
        $archivedProducts = Product::with('category')->where('status', 'archived')->orderByDesc('archived_at')->get();
        return view('inventory.archive', compact('archivedProducts'));
    }

    // Low stock report, grouped by product — now checks SELLABLE stock
    // (stock minus damaged), not raw stock, so a product that's only
    // "low" because half its units are damaged still shows up here.
    public function lowStock()
    {
        $variants = ProductVariant::with('product')
            ->whereRaw('(stock_quantity - damaged_quantity) <= low_stock_threshold')
            ->orderByRaw('stock_quantity - damaged_quantity')
            ->get();

        return view('inventory.low-stock', compact('variants'));
    }
}
