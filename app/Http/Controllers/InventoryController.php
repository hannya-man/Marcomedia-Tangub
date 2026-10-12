<?php
namespace App\Http\Controllers;

use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Category;
use App\Models\SaleItem;
use App\Services\Inventory\BackorderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InventoryController extends Controller
{
    public function index(Request $request)
    {
        // A board's cut sizes count toward its stock status (Material::getStockStatusAttribute).
        // Sizes in the shop's order (its price list's).
        $query = Product::with(['variants' => fn ($q) => $q->inShopOrder(), 'variants.materials.cutSizes', 'category'])
            ->where('status', '!=', 'archived');

        if ($request->filled('search')) {
            $query->where('name', 'like', "%{$request->search}%");
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        $products = $query->inShopOrder()->paginate(15)->withQueryString();
        // Back links (Categories, Archive) return to this list as it was: its search, category and page.
        $request->session()->put('inventory.list_url', $request->fullUrl());
        $categories = Category::whereNull('archived_at')->inShopOrder()->get();
        $archivedCount = Product::where('status', 'archived')->count();
        // "Made from" choices. Whole boards and rolls aren't: a product uses their standard pieces
        // ("Sintra Board - Long"), which cut the next board themselves when they run out.
        $materials = \App\Models\Material::whereNull('archived_at')->whereNull('sheet_width')->orderBy('name')->get();

        return view('inventory.index', compact('products', 'categories', 'archivedCount', 'materials'));
    }

    // Add Product now lives inline on the index page as a popout panel —
    // no separate create page needed anymore.

    public function store(Request $request)
    {
        // Sizes count only while "comes in different sizes" is ticked. Sizes added and then unticked
        // were saved anyway: hidden from Products and Billing, and holding their SKUs.
        if (! $request->boolean('has_variants')) {
            $request->request->remove('variants');
        }
        $this->blankMaterialsToNull($request);

        $validated = $request->validate([
            'category_id' => ['nullable', Rule::exists('categories', 'id')->whereNull('archived_at')],
            'new_category_name' => ['nullable', 'string', 'max:100', 'regex:/[\pL\pN]/u'],
            'name' => 'required|string|max:150',
            'sku' => 'required|string|max:60|unique:products,sku',
            'description' => 'nullable|string|max:2000',
            'base_price' => 'required|numeric|min:0|max:9999999',
            'has_variants' => 'boolean',
            'track_inventory' => 'boolean',
            'variants' => 'nullable|array|max:300|required_if:has_variants,1',
            'variants.*.variant_name' => 'required|string|max:100',
            // A SKU already on another size used to fail at the database, after the product was saved.
            'variants.*.sku' => ['required', 'string', 'max:60', 'distinct', Rule::unique('product_variants', 'sku')],
            'variants.*.price' => 'nullable|numeric|min:0|max:9999999',
            'variants.*.low_stock_threshold' => 'nullable|integer|min:0|max:1000000',
            'variants.*.materials' => 'nullable|array|max:20',
            'variants.*.materials.*.material_id' => ['nullable', Rule::exists('materials', 'id')->whereNull('archived_at')],
            'variants.*.materials.*.quantity_per_unit' => 'nullable|numeric|min:0|max:100000',
        ], [
            'sku.unique' => 'Another product already uses the SKU :input (one in the Archive counts too).',
            'new_category_name.regex' => 'Use a category name with letters or numbers.',
            'variants.required_if' => 'Add at least one size, or untick "This product comes in different sizes".',
            'variants.*.variant_name.required' => 'Every size needs a name.',
            'variants.*.sku.required' => 'Every size needs a SKU.',
            'variants.*.sku.distinct' => 'Two sizes have the same SKU.',
            'variants.*.sku.unique' => 'The SKU :input is already used by another size.',
        ]);

        $product = DB::transaction(fn () => $this->createProduct($request, $validated));

        return redirect()->route('inventory.index')->with('success', "Product '{$product->name}' created.");
    }

    // All or nothing: a size that failed to save used to leave the product saved without it.
    private function createProduct(Request $request, array $validated): Product
    {
        // If they typed a new category instead of picking one, create it now.
        $categoryId = $validated['category_id'] ?? null;
        if (!empty($validated['new_category_name'])) {
            $category = Category::firstOrCreate(
                ['slug' => str($validated['new_category_name'])->slug()],
                ['name' => $validated['new_category_name']]
            );
            // The name of an archived category brings it back: the product was filed under a
            // category no list shows.
            if ($category->archived_at) {
                $category->update(['archived_at' => null]);
            }
            $categoryId = $category->id;
        }

        $product = Product::create([
            'category_id' => $categoryId,
            'name' => $validated['name'],
            'sku' => $validated['sku'],
            'description' => $validated['description'] ?? null,
            'base_price' => $validated['base_price'],
            'has_variants' => ! empty($validated['variants']),
            // An unticked box sends nothing, so "missing" means made to order. (The old default of
            // true made every product a stocked one, whatever the box said.)
            'track_inventory' => $request->boolean('track_inventory'),
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
            // (and usually does) draw from more than one at once. One listed twice is one line.
            $this->syncMaterials($variant, $v['materials'] ?? []);
        }

        return $product;
    }

    // The owner's edit page: the product, its sizes and their prices, and what each size is made of.
    public function edit(Product $product)
    {
        $product->load(['variants' => fn ($q) => $q->inShopOrder(), 'variants.materials']);
        $cutIds = $product->variants->filter(fn ($v) => $this->isCut($v))->pluck('id')->values();

        // A save that was refused comes back with what was typed; else the product as it is.
        $rows = old('variants') !== null
            ? collect(old('variants'))->values()->map(fn ($r) => [
                'id' => isset($r['id']) && $r['id'] !== '' ? (int) $r['id'] : null,
                'variant_name' => $r['variant_name'] ?? '',
                'sku' => $r['sku'] ?? '',
                'price' => $r['price'] ?? '',
                'low_stock_threshold' => $r['low_stock_threshold'] ?? 5,
                'active' => ! empty($r['active']),
                'remove' => ! empty($r['remove']),
                'materials' => array_values($r['materials'] ?? []),
            ])
            : $product->variants->map(fn ($v) => [
                'id' => $v->id,
                'variant_name' => $v->variant_name,
                'sku' => $v->sku,
                'price' => $v->price !== null ? (float) $v->price : '',
                'low_stock_threshold' => $v->low_stock_threshold,
                'active' => $v->status === 'active',
                'remove' => false,
                'materials' => $cutIds->contains($v->id) ? [] : $v->materials->map(fn ($m) => [
                    'material_id' => $m->id, 'quantity_per_unit' => (float) $m->pivot->quantity_per_unit,
                ])->values()->all(),
            ]);

        // "Made from" choices, as on Add Product, plus any the sizes already use (even archived).
        $usedIds = $product->variants->reject(fn ($v) => $cutIds->contains($v->id))->flatMap->materials->pluck('id');
        $materials = Material::whereNull('sheet_width')
            ->where(fn ($q) => $q->whereNull('archived_at')->orWhereIn('id', $usedIds))
            ->orderBy('name')->get(['id', 'name', 'unit', 'archived_at']);
        $categories = Category::whereNull('archived_at')->orWhere('id', $product->category_id)->inShopOrder()->get();
        // Boards or rolls the sizes are cut from or printed on (a board's sizes are set on its card).
        $cutFrom = $product->variants->flatMap->materials->filter->isSheet()->unique('id')->values();

        return view('inventory.edit', compact('product', 'rows', 'cutIds', 'cutFrom', 'materials', 'categories'));
    }

    public function update(Request $request, Product $product)
    {
        $this->blankMaterialsToNull($request);

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'sku' => ['required', 'string', 'max:60', Rule::unique('products', 'sku')->ignore($product->id)],
            'category_id' => ['nullable', Rule::exists('categories', 'id')],
            'description' => 'nullable|string|max:2000',
            'base_price' => 'required|numeric|min:0|max:9999999',
            'track_inventory' => 'boolean',
            'variants' => 'nullable|array|max:300',
            'variants.*.id' => 'nullable|integer',
            'variants.*.variant_name' => 'required|string|max:100',
            'variants.*.sku' => 'required|string|max:60|distinct',
            'variants.*.price' => 'nullable|numeric|min:0|max:9999999',
            'variants.*.low_stock_threshold' => 'nullable|integer|min:0|max:1000000',
            'variants.*.active' => 'boolean',
            'variants.*.remove' => 'boolean',
            'variants.*.materials' => 'nullable|array|max:20',
            'variants.*.materials.*.material_id' => ['nullable', Rule::exists('materials', 'id')],
            'variants.*.materials.*.quantity_per_unit' => 'nullable|numeric|min:0|max:100000',
        ], [
            'variants.*.variant_name.required' => 'Every size needs a name.',
            'variants.*.sku.required' => 'Every size needs a SKU.',
            'variants.*.sku.distinct' => 'Two sizes have the same SKU.',
        ]);

        $existing = $product->variants()->with('materials')->get()->keyBy('id');
        $rows = array_values($validated['variants'] ?? []);
        $errors = [];
        foreach ($rows as $i => $row) {
            $id = $row['id'] ?? null;
            if ($id && ! $existing->has($id)) {
                $errors["variants.{$i}.id"] = "A size on the form isn't one of {$product->name}'s. Reload the page and try again.";
            } elseif (ProductVariant::where('sku', $row['sku'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                $errors["variants.{$i}.sku"] = "The SKU {$row['sku']} is already used by another size.";
            }
        }

        // Switching between made to order and keeping a count changes how its sales take stock, so not
        // while something sold is still waiting for stock.
        $track = $request->boolean('track_inventory');
        $switching = $track !== (bool) $product->track_inventory;
        if ($switching && SaleItem::onOrder()->where('product_id', $product->id)
                ->whereHas('sale', fn ($q) => $q->whereIn('status', ['pending', 'processing']))->exists()) {
            $errors['track_inventory'] = "{$product->name} has items on order. Change how it's stocked once they've taken their stock, or are voided.";
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        $hidden = DB::transaction(function () use ($product, $validated, $rows, $existing, $track, $switching) {
            $product->update([
                'name' => $validated['name'],
                'sku' => $validated['sku'],
                'category_id' => $validated['category_id'] ?? null,
                'description' => $validated['description'] ?? null,
                'base_price' => $validated['base_price'],
                'track_inventory' => $track,
            ]);
            // Made to order kept no count (a void used to add one anyway): a count starts from 0,
            // and what's on hand is added with Restock.
            if ($switching && $track) {
                $product->variants()->update(['stock_quantity' => 0, 'damaged_quantity' => 0]);
            }

            $hidden = [];
            foreach ($rows as $row) {
                $variant = isset($row['id']) ? $existing->get($row['id']) : null;
                if (! empty($row['remove'])) {
                    if ($variant && $this->variantUsed($variant)) {
                        // Sold before: kept for its sales history, no longer sold.
                        $variant->update(['status' => 'inactive']);
                        $hidden[] = $variant->variant_name;
                    } elseif ($variant) {
                        $variant->delete();   // its recipe lines go with it
                    }
                    continue;
                }

                $threshold = $row['low_stock_threshold'] ?? null;
                $values = [
                    'variant_name' => $row['variant_name'],
                    'sku' => $row['sku'],
                    'price' => $row['price'] ?? null,   // blank: the base price, or at the counter
                    'status' => ($row['active'] ?? true) ? 'active' : 'inactive',
                ] + ($threshold !== null ? ['low_stock_threshold' => $threshold, 'reorder_point' => $threshold * 2] : []);

                if ($variant) {
                    $variant->update($values);
                } else {
                    $variant = $product->variants()->create($values + [
                        'stock_quantity' => 0, 'low_stock_threshold' => $threshold ?? 5, 'reorder_point' => ($threshold ?? 5) * 2,
                    ]);
                }
                // A size cut from a board or roll keeps its recipe: it's set on the board's card.
                if (! $this->isCut($variant)) {
                    $this->syncMaterials($variant, $row['materials'] ?? []);
                }
            }
            $product->update(['has_variants' => $product->variants()->exists()]);

            return $hidden;
        });

        $message = "Saved {$product->name}.";
        if ($hidden) {
            $message .= ' Hidden instead of removed, since they have sales: ' . implode(', ', $hidden) . '.';
        }
        if ($switching && $track) {
            $message .= ' Its stock count starts at 0: add what you have with the + in Restock.';
        }

        return redirect()->to(session('inventory.list_url', route('inventory.index')))->with('success', $message);
    }

    // Native selects send "" for "none", and `nullable` only skips null: blanks become null first,
    // so an empty material row doesn't fail `exists` and block the whole save.
    private function blankMaterialsToNull(Request $request): void
    {
        $variantsInput = $request->input('variants', []);
        foreach ($variantsInput as $i => $variant) {
            foreach (['price', 'low_stock_threshold'] as $field) {
                if (($variant[$field] ?? null) === '') {
                    $variantsInput[$i][$field] = null;
                }
            }
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
    }

    // Cut from a board or roll (sintra board, tarpaulin): its recipe is the board, by size.
    private function isCut(ProductVariant $variant): bool
    {
        return $variant->materials->contains(fn ($m) => $m->isSheet());
    }

    // Sold, counted or written off before: removing it would break that history.
    private function variantUsed(ProductVariant $variant): bool
    {
        return DB::table('sale_items')->where('product_variant_id', $variant->id)->exists()
            || DB::table('inventory_movements')->where('product_variant_id', $variant->id)->exists()
            || DB::table('physical_counts')->where('product_variant_id', $variant->id)->exists()
            || DB::table('rejected_outputs')->where('product_variant_id', $variant->id)->exists();
    }

    // What one unit of the size takes: a fixed amount of each material. The same material twice adds up.
    private function syncMaterials(ProductVariant $variant, array $lines): void
    {
        $sync = [];
        foreach ($lines as $line) {
            $id = (int) ($line['material_id'] ?? 0);
            $qty = (float) ($line['quantity_per_unit'] ?? 0);
            if ($id > 0 && $qty > 0) {
                $sync[$id] = ['quantity_per_unit' => round(($sync[$id]['quantity_per_unit'] ?? 0) + $qty, 3), 'consumption_type' => 'fixed'];
            }
        }
        $variant->materials()->sync($sync);
    }

    // Restock or manually adjust a single variant. Stock added goes first to items sold while it
    // was out (on order).
    public function adjustStock(Request $request, ProductVariant $variant, BackorderService $backorders)
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

        return back()->with('success', "Stock updated for {$variant->variant_name}." . ($signedQty > 0 ? $backorders->fillAfterRestock(Auth::id()) : ''));
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
