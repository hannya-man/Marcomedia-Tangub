<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// The shop's flyer as products, in the flyer's order: each section down its left column,
// then its right. Three sections, one category each:
//   Customized Items                  souvenirs, tokens and gifts
//   Sublimation, DTP & DTF Printing   apparel and banners (was "Apparel / Sublimation")
//   Printing & Other Services         the flyer's third, unnamed block (was "PVC ID Printing")
// One flyer bullet is one product. The lines under a bullet are its types: the mug bullet lists
// Regular, Magic, Frosted, Transparent and Beer Mug. A product with no types gets one, "Standard",
// because the materials a product uses are listed per type (2026_10_06_000005).
// Two products get types the flyer doesn't list, so each uses the right paper: Document Printing
// (Short, A4, Long) and Photo Printing (4R, A4).
// Everything is made to order, with no finished-goods count: a sale uses its materials. The four
// products the shop already had take the flyer's names; their sizes and sales stay. New items
// have no set price yet: the cashier enters it at Billing/POS.
return new class extends Migration {
    private const SECTIONS = [
        [
            'name' => 'Customized Items', 'slug' => 'customized-items', 'old_slug' => null, 'prefix' => 'CI',
            'items' => [
                ['Mug', 'existing' => 'MUG-REG-001', 'was' => 'Regular Mug',
                    'rename' => ['11oz' => 'Regular 11oz', '15oz' => 'Regular 15oz'],
                    'types' => ['Regular 11oz', 'Regular 15oz', 'Magic', 'Frosted', 'Transparent', 'Beer Mug']],
                ['Tumbler', 'existing' => 'TUMB-001', 'was' => 'Tumbler', 'types' => ['20oz', '30oz']],
                'Foldable Fan', 'Ref Magnet', 'Keychain', 'Bottle Opener', 'Magic Mirror', 'Table Nametag',
                'Name Plate', 'Badge Pin', 'Button Pin', 'Umbrella',
                ['Mousepad', 'types' => ['Regular', 'Gaming']],
                'Cellphone Case',
                'Plate', 'Rock Photo', 'Glass Clock', 'Pillow', 'Totebag', 'Eco Bag', 'Wallet', 'Notebook', 'Pen',
                'Pencil', 'Planner', 'Cap', 'Handkerchief',
                ['Any Printable Item', 'description' => 'Anything printable that is not on the list. Describe it in the notes.'],
            ],
        ],
        [
            'name' => 'Sublimation, DTP & DTF Printing', 'slug' => 'sublimation-dtp-dtf-printing', 'old_slug' => 'apparel-sublimation', 'prefix' => 'SP',
            'items' => [
                ['T-Shirt', 'existing' => 'TSHIRT-SUB-002', 'was' => 'Sublimation T-Shirt',
                    'types' => ['Small (S)', 'Medium (M)', 'Large (L)', 'X-Large (XL)']],
                'Jacket', 'Vest', 'Jersey', 'Banner',
                'Polo Shirt', 'Sweatshirt', 'PE Uniform', 'Apron', 'Sablay',
                ['Other Apparel', 'description' => 'Any other garment or item for sublimation, DTP or DTF. Describe it in the notes.'],
            ],
        ],
        [
            'name' => 'Printing & Other Services', 'slug' => 'printing-other-services', 'old_slug' => 'pvc-id-printing', 'prefix' => 'PS',
            'items' => [
                'Lanyard',
                ['PVC ID', 'existing' => 'PVCID-001', 'was' => 'PVC ID', 'types' => ['Standard']],
                'Lamination', 'Trophy', 'Plaque', 'Medal', 'Sash', 'Lei',
                ['Document Printing', 'types' => ['Short', 'A4', 'Long']],
                'Magazine', 'Brochure', 'Bookbinding', 'Yearbook', 'Programme', 'Booklet',
                ['Photo Printing', 'types' => ['4R', 'A4']],
                'Frame', 'Sintra Board', 'Glass', 'Acrylic', 'Directory', 'Wall Frame',
                'Stand Frame', '3D Frame', 'Tarpaulin', 'Sticker', 'Calling Card',
                ['Invitation Card', 'types' => ['Wedding', 'Birthday/Debut', 'Christening', 'Any Event']],
            ],
        ],
    ];

    public function up(): void {
        $now = now();
        foreach (self::SECTIONS as $s => $section) {
            $categoryId = $this->category($section, $s + 1, $now);
            foreach ($this->items($section) as $p => $item) {
                $this->product($item, $section['prefix'], $categoryId, $p + 1, $now);
            }
        }
    }

    public function down(): void {
        // Removes what this added, only while none of it has been sold. The shop's own products get
        // back their names, sizes and stock counting. The sections keep their new names and order.
        $created = collect();       // product ids this added
        $addedTypes = collect();    // type ids this added to the shop's own products
        $restore = [];
        foreach (self::SECTIONS as $section) {
            foreach ($this->items($section) as $item) {
                if (isset($item['was'])) {
                    $id = DB::table('products')->where('sku', $item['existing'])->value('id');
                    if (! $id) {
                        continue;
                    }
                    $restore[$id] = $item;
                    $addedTypes = $addedTypes->merge(DB::table('product_variants')->where('product_id', $id)
                        ->whereIn('sku', collect($item['types'])->map(fn ($t) => $item['existing'] . '-' . $this->code($t)))->pluck('id'));
                } else {
                    $created = $created->merge(DB::table('products')->where('sku', $this->sku($section['prefix'], $item[0]))->pluck('id'));
                }
            }
        }

        $sold = DB::table('sale_items')->whereIn('product_id', $created)->orWhereIn('product_variant_id', $addedTypes)->count();
        if ($sold > 0) {
            throw new RuntimeException("{$sold} sale line(s) use the flyer items. Archive them on the Products page instead of rolling back.");
        }

        DB::table('product_variants')->whereIn('id', $addedTypes)->delete();
        DB::table('product_variants')->whereIn('product_id', $created)->delete();
        DB::table('products')->whereIn('id', $created)->delete();

        foreach ($restore as $id => $item) {
            DB::table('products')->where('id', $id)->update(['name' => $item['was'], 'track_inventory' => true]);
            foreach ($item['rename'] ?? [] as $old => $new) {
                DB::table('product_variants')->where('product_id', $id)->where('variant_name', $new)->update(['variant_name' => $old]);
            }
        }
    }

    // A section's items, each as [name, options...].
    private function items(array $section): array {
        return array_map(fn ($item) => is_string($item) ? [$item] : $item, $section['items']);
    }

    // The section's category: found by its slug, or the one it used to have, and renamed to match.
    private function category(array $section, int $order, $now): int {
        $row = DB::table('categories')->where('slug', $section['slug'])->first()
            ?? ($section['old_slug'] ? DB::table('categories')->where('slug', $section['old_slug'])->first() : null);

        $values = ['name' => $section['name'], 'slug' => $section['slug'], 'sort_order' => $order, 'updated_at' => $now];
        if ($row) {
            DB::table('categories')->where('id', $row->id)->update($values);
            return $row->id;
        }

        return DB::table('categories')->insertGetId($values + ['created_at' => $now]);
    }

    private function product(array $item, string $prefix, int $categoryId, int $order, $now): void {
        $name = $item[0];
        $sku = $this->sku($prefix, $name);
        $values = [
            'category_id' => $categoryId, 'sort_order' => $order, 'name' => $name,
            'has_variants' => true, 'track_inventory' => false, 'updated_at' => $now,
        ];

        // Already on the list (by SKU, else by name): it keeps its price, sizes and sales.
        $id = DB::table('products')->where('status', '!=', 'archived')
            ->whereIn('sku', array_filter([$item['existing'] ?? null, $sku]))->value('id')
            ?? DB::table('products')->where('status', '!=', 'archived')->where('name', $item['was'] ?? $name)->value('id');

        if ($id) {
            DB::table('products')->where('id', $id)->update($values);
            foreach ($item['rename'] ?? [] as $old => $new) {
                DB::table('product_variants')->where('product_id', $id)->where('variant_name', $old)->update(['variant_name' => $new, 'updated_at' => $now]);
            }
        } else {
            $id = DB::table('products')->insertGetId($values + [
                'sku' => $this->freeSku('products', $sku),
                'description' => $item['description'] ?? null,
                'base_price' => 0,
                'status' => 'active',
                'created_at' => $now,
            ]);
        }

        // Its types. A product with sizes of its own and none listed here keeps just those.
        $productSku = DB::table('products')->where('id', $id)->value('sku');
        $types = $item['types'] ?? (DB::table('product_variants')->where('product_id', $id)->exists() ? [] : ['Standard']);
        foreach ($types as $type) {
            if (DB::table('product_variants')->where('product_id', $id)->where('variant_name', $type)->exists()) {
                continue;
            }
            DB::table('product_variants')->insert([
                'product_id' => $id,
                'variant_name' => $type,
                'sku' => $this->freeSku('product_variants', $productSku . '-' . $this->code($type)),
                'price' => null,
                'stock_quantity' => 0,
                'low_stock_threshold' => 0,
                'reorder_point' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    // CI-MAGIC-MUG, PS-3D-FRAME
    private function sku(string $prefix, string $name): string {
        return $prefix . '-' . $this->code($name);
    }

    // "Birthday/Debut" -> BIRTHDAY-DEBUT
    private function code(string $name): string {
        return Str::upper(Str::slug(str_replace('/', ' ', $name)));
    }

    // The SKU, or SKU-2, SKU-3... if something already uses it.
    private function freeSku(string $table, string $sku): string {
        $free = $sku;
        for ($i = 2; DB::table($table)->where('sku', $free)->exists(); $i++) {
            $free = $sku . '-' . $i;
        }
        return $free;
    }
};
