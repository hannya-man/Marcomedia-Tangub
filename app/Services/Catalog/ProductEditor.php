<?php
namespace App\Services\Catalog;

use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SaleItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Adding and changing a product: the checks and the saving. Used by an owner's own save
 * (InventoryController) and by an owner approving a cashier's request (ApprovalController), which
 * checks the form the cashier sent again: the catalog may have changed since it was sent.
 * Also says in plain words what a request would add or change, for the owner to review.
 */
class ProductEditor
{
    // ---------------------------------------------------------------- Add Product

    // The Add Product form as sent. Sizes count only while "comes in different sizes" is ticked:
    // sizes added and then unticked were saved anyway, hidden from Products and Billing.
    public function normalizeNew(array $input): array
    {
        if (! $this->bool($input['has_variants'] ?? false)) {
            unset($input['variants']);
        }

        return $this->blanksToNull($input);
    }

    public function validateNew(array $input): array
    {
        return Validator::make($input, [
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
        ])->validate();
    }

    // All or nothing: a size that failed to save used to leave the product saved without it.
    // $input: normalized and checked (validateNew).
    public function create(array $input): Product
    {
        return DB::transaction(function () use ($input) {
            // If they typed a new category instead of picking one, create it now.
            $categoryId = $input['category_id'] ?? null;
            if (! empty($input['new_category_name'])) {
                $category = Category::firstOrCreate(
                    ['slug' => str($input['new_category_name'])->slug()],
                    ['name' => $input['new_category_name']]
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
                'name' => $input['name'],
                'sku' => $input['sku'],
                'description' => $input['description'] ?? null,
                'base_price' => $input['base_price'],
                'has_variants' => ! empty($input['variants']),
                // An unticked box sends nothing, so "missing" means made to order. (The old default of
                // true made every product a stocked one, whatever the box said.)
                'track_inventory' => $this->bool($input['track_inventory'] ?? false),
                'status' => 'active',
            ]);

            // Sizes are created at 0 stock — quantity only ever enters through
            // a batch / restock transaction, never at product creation. This
            // keeps Product (the master record) and Stock (a separate,
            // batch-based concept) from getting tangled together.
            foreach ($input['variants'] ?? [] as $v) {
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
        });
    }

    // ---------------------------------------------------------------- Edit Product

    public function normalizeChanges(array $input): array
    {
        return $this->blanksToNull($input);
    }

    // The edit form, checked against the product as it is now. Throws ValidationException.
    public function validateChanges(Product $product, array $input): array
    {
        $validated = Validator::make($input, [
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
        ])->validate();

        $existing = $product->variants()->get()->keyBy('id');
        $errors = [];
        foreach (array_values($validated['variants'] ?? []) as $i => $row) {
            $id = $row['id'] ?? null;
            if ($id && ! $existing->has($id)) {
                $errors["variants.{$i}.id"] = "A size on the form isn't one of {$product->name}'s. Reload the page and try again.";
            } elseif (ProductVariant::where('sku', $row['sku'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists()) {
                $errors["variants.{$i}.sku"] = "The SKU {$row['sku']} is already used by another size.";
            }
        }

        // Switching between made to order and keeping a count changes how its sales take stock, so not
        // while something sold is still waiting for stock.
        $track = $this->bool($input['track_inventory'] ?? false);
        if ($track !== (bool) $product->track_inventory && SaleItem::onOrder()->where('product_id', $product->id)
                ->whereHas('sale', fn ($q) => $q->whereIn('status', ['pending', 'processing']))->exists()) {
            $errors['track_inventory'] = "{$product->name} has items on order. Change how it's stocked once they've taken their stock, or are voided.";
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $validated + ['track_inventory' => $track];
    }

    // Saves checked changes (validateChanges). Returns the sizes hidden instead of removed (they
    // have sales), and whether a stock count started from 0 (it was made to order).
    public function update(Product $product, array $validated): array
    {
        $existing = $product->variants()->with('materials')->get()->keyBy('id');
        $rows = array_values($validated['variants'] ?? []);
        $track = $this->bool($validated['track_inventory'] ?? false);
        $switching = $track !== (bool) $product->track_inventory;

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

        return ['hidden' => $hidden, 'count_started' => $switching && $track];
    }

    // Cut from a board or roll (sintra board, tarpaulin): its recipe is the board, by size.
    public function isCut(ProductVariant $variant): bool
    {
        return $variant->materials->contains(fn ($m) => $m->isSheet());
    }

    // ---------------------------------------------------------------- In plain words, for review

    // A new product as a request would add it: its details and each size with what it's made from.
    public function describeNew(array $input): array
    {
        $materials = $this->materialsIn($input);
        $category = ! empty($input['new_category_name'])
            ? $input['new_category_name'] . ' (new category)'
            : (Category::find($input['category_id'] ?? null)->name ?? 'No category');

        return [
            'name' => $input['name'] ?? '',
            'sku' => $input['sku'] ?? '',
            'category' => $category,
            'base_price' => (float) ($input['base_price'] ?? 0),
            'description' => $input['description'] ?? null,
            'track' => $this->bool($input['track_inventory'] ?? false),
            'sizes' => collect($input['variants'] ?? [])->values()->map(fn ($v) => [
                'name' => $v['variant_name'] ?? '',
                'sku' => $v['sku'] ?? '',
                'price' => $v['price'] ?? null,
                'made_from' => $this->recipeText($v['materials'] ?? [], $materials),
            ])->all(),
        ];
    }

    // What approving an edit would change, line by line, against the product as it is now.
    public function describeChanges(Product $product, array $input): array
    {
        $lines = [];
        $money = fn ($p) => $p === null || $p === '' ? 'none' : '₱' . number_format((float) $p, 2);
        $same = fn ($a, $b) => (string) ($a ?? '') === (string) ($b ?? '');

        if (! $same($product->name, $input['name'] ?? null)) {
            $lines[] = "Name: {$product->name} → " . ($input['name'] ?? '');
        }
        if (! $same($product->sku, $input['sku'] ?? null)) {
            $lines[] = "SKU: {$product->sku} → " . ($input['sku'] ?? '');
        }
        if ((int) $product->category_id !== (int) ($input['category_id'] ?? 0)) {
            $old = $product->category->name ?? 'No category';
            $new = Category::find($input['category_id'] ?? null)->name ?? 'No category';
            $lines[] = "Category: {$old} → {$new}";
        }
        if (round((float) $product->base_price, 2) !== round((float) ($input['base_price'] ?? 0), 2)) {
            $lines[] = 'Base price: ' . $money($product->base_price) . ' → ' . $money($input['base_price'] ?? 0);
        }
        if (! $same($product->description, $input['description'] ?? null)) {
            $lines[] = 'Description: "' . ($input['description'] ?? '') . '"';
        }
        $track = $this->bool($input['track_inventory'] ?? false);
        if ($track !== (bool) $product->track_inventory) {
            $lines[] = $track ? 'Starts keeping a stock count (from 0)' : 'Stops keeping a stock count: made to order';
        }

        $existing = $product->variants()->with('materials')->get()->keyBy('id');
        $materials = $this->materialsIn($input);
        foreach ($input['variants'] ?? [] as $row) {
            $variant = ! empty($row['id']) ? $existing->get($row['id']) : null;
            if (! $variant) {
                $lines[] = 'New size: ' . ($row['variant_name'] ?? '') . ' (' . ($row['sku'] ?? '') . '), ' . $money($row['price'] ?? null)
                    . (($text = $this->recipeText($row['materials'] ?? [], $materials)) ? ", made from {$text}" : '');
                continue;
            }
            if (! empty($row['remove'])) {
                $lines[] = "Remove size: {$variant->variant_name}";
                continue;
            }

            $changes = [];
            if (! $same($variant->variant_name, $row['variant_name'] ?? null)) {
                $changes[] = 'name → ' . ($row['variant_name'] ?? '');
            }
            if (! $same($variant->sku, $row['sku'] ?? null)) {
                $changes[] = 'SKU → ' . ($row['sku'] ?? '');
            }
            $oldPrice = $variant->price === null ? null : round((float) $variant->price, 2);
            $newPrice = ($row['price'] ?? null) === null ? null : round((float) $row['price'], 2);
            if ($oldPrice !== $newPrice) {
                $changes[] = 'price ' . $money($oldPrice) . ' → ' . $money($newPrice);
            }
            if (($row['low_stock_threshold'] ?? null) !== null && (int) $row['low_stock_threshold'] !== (int) $variant->low_stock_threshold) {
                $changes[] = "low stock alert {$variant->low_stock_threshold} → {$row['low_stock_threshold']}";
            }
            $active = $this->bool($row['active'] ?? true);
            if ($active !== ($variant->status === 'active')) {
                $changes[] = $active ? 'sold again' : 'no longer sold';
            }
            if (! $this->isCut($variant)) {
                $old = $variant->materials->mapWithKeys(fn ($m) => [$m->id => round((float) $m->pivot->quantity_per_unit, 3)])->sortKeys()->all();
                $new = collect($this->recipe($row['materials'] ?? []))->map(fn ($l) => $l['quantity_per_unit'])->sortKeys()->all();
                if ($old != $new) {
                    $changes[] = 'made from ' . ($this->recipeText($row['materials'] ?? [], $materials) ?: 'nothing');
                }
            }
            if ($changes) {
                $lines[] = "Size {$variant->variant_name}: " . implode('; ', $changes);
            }
        }

        return $lines;
    }

    // ---------------------------------------------------------------- Helpers

    // Native selects send "" for "none", and `nullable` only skips null: blanks become null first,
    // so an empty material row doesn't fail `exists` and block the whole save.
    private function blanksToNull(array $input): array
    {
        $variants = is_array($input['variants'] ?? null) ? $input['variants'] : [];
        foreach ($variants as $i => $variant) {
            if (! is_array($variant)) {
                continue;
            }
            foreach (['price', 'low_stock_threshold'] as $field) {
                if (($variant[$field] ?? null) === '') {
                    $variants[$i][$field] = null;
                }
            }
            foreach (is_array($variant['materials'] ?? null) ? $variant['materials'] : [] as $j => $vm) {
                if (($vm['material_id'] ?? '') === '') {
                    $variants[$i]['materials'][$j]['material_id'] = null;
                }
                if (($vm['quantity_per_unit'] ?? '') === '') {
                    $variants[$i]['materials'][$j]['quantity_per_unit'] = null;
                }
            }
        }
        $input['variants'] = $variants;

        return $input;
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
    private function recipe(array $lines): array
    {
        $recipe = [];
        foreach ($lines as $line) {
            $id = (int) ($line['material_id'] ?? 0);
            $qty = (float) ($line['quantity_per_unit'] ?? 0);
            if ($id > 0 && $qty > 0) {
                $recipe[$id] = ['quantity_per_unit' => round(($recipe[$id]['quantity_per_unit'] ?? 0) + $qty, 3), 'consumption_type' => 'fixed'];
            }
        }

        return $recipe;
    }

    private function syncMaterials(ProductVariant $variant, array $lines): void
    {
        $variant->materials()->sync($this->recipe($lines));
    }

    private function materialsIn(array $input)
    {
        $ids = collect($input['variants'] ?? [])->flatMap(fn ($v) => collect($v['materials'] ?? [])->pluck('material_id'))->filter()->unique();

        return Material::whereIn('id', $ids)->get(['id', 'name', 'unit'])->keyBy('id');
    }

    // "Ceramic Mug Blank 1 pcs, Sublimation Ink 2 ml"
    private function recipeText(array $lines, $materials): string
    {
        return collect($this->recipe($lines))->map(function ($line, $id) use ($materials) {
            $m = $materials->get($id);
            $qty = rtrim(rtrim(number_format($line['quantity_per_unit'], 3), '0'), '.');

            return ($m->name ?? "Material #{$id}") . " {$qty}" . ($m ? " {$m->unit}" : '');
        })->implode(', ');
    }

    // As a checkbox or hidden field sends it: "1", "0", "on", true, false.
    private function bool($value): bool
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
