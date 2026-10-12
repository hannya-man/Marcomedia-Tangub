<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Fixed yields for boards and rolls, instead of cutting any size off an open sheet and keeping
// track of what's left over.
//   A board (sintra, acrylic) or a roll (tarpaulin) has a set size and is counted whole.
//   Its cut sizes are standard pieces. Each has a yield: how many pieces one board gives, worked
//   out from the real sizes (one 4 x 8 ft board gives 35 long-bond pieces). A roll's piece is one
//   foot of its length at full width, so a 164 ft roll gives 164.
//   Cutting a board turns all of it into pieces of one size. A piece costs the board's cost
//   divided by the yield, so the trim is paid for in the pieces and never tracked as offcuts.
//   Pieces are discrete materials of their own (cut_from_id = their board): a sale takes them
//   one by one, a void puts them back, and when a size runs out the next board is cut for it.
//   A sale line keeps the piece it was charged as (cut_material_id) and how many (cut_blocks).
//   A custom size is charged as the smallest standard piece it fits in, or as whole feet of a roll.
// Also moves sintra board and acrylic to Discrete (boards are counted one by one), and gives the
// items cut from them their standard sizes as types, plus "Custom size".
return new class extends Migration {
    private const BOARDS = ['Sintra Board' => 'Sintra Board', 'Acrylic Sheet (3mm)' => 'Acrylic'];   // board => item cut from it

    // [name, width, height, unit, code]. A size that doesn't fit the board is left out.
    private const BOARD_SIZES = [
        ['Long (8.5 x 13 in)', 8.5, 13, 'in', 'LONG'],
        ['Short (8.5 x 11 in)', 8.5, 11, 'in', 'SHORT'],
        ['A4 (8.3 x 11.7 in)', 8.27, 11.69, 'in', 'A4'],
        ['A3 (11.7 x 16.5 in)', 11.69, 16.54, 'in', 'A3'],
        ['Small (4 x 6 in)', 4, 6, 'in', 'SMALL'],
        ['12 x 18 in', 12, 18, 'in', '1218'],
        ['2 x 3 ft', 2, 3, 'ft', '2X3'],
        ['Half board (4 x 4 ft)', 4, 4, 'ft', 'HALF'],
    ];

    private const ROLLS = ['Tarpaulin Roll 3 ft', 'Tarpaulin Roll 4 ft', 'Tarpaulin Roll 5 ft', 'Tarpaulin Roll 6 ft'];

    // Tarpaulin sizes sold, in feet.
    private const TARP_SIZES = [[2, 3], [3, 4], [3, 5], [4, 6], [4, 8], [5, 10]];

    public function up(): void {
        Schema::table('materials', function (Blueprint $table) {
            $table->enum('sheet_kind', ['board', 'roll'])->nullable()->after('sheet_height');
            $table->foreignId('cut_from_id')->nullable()->after('sheet_kind')->constrained('materials')->restrictOnDelete();
            $table->decimal('cut_width_in', 8, 2)->nullable()->after('cut_from_id');
            $table->decimal('cut_height_in', 8, 2)->nullable()->after('cut_width_in');
            $table->unsignedInteger('cut_yield')->nullable()->after('cut_height_in');
        });
        Schema::table('sale_items', function (Blueprint $table) {
            $table->foreignId('cut_material_id')->nullable()->after('height')->constrained('materials')->nullOnDelete();
            $table->unsignedSmallInteger('cut_blocks')->nullable()->after('cut_material_id');
        });
        // A sale line's size is kept in feet: two decimals rounded 10 in (0.8333 ft) to 9.96 in.
        DB::statement('ALTER TABLE sale_items MODIFY width DECIMAL(10,4) NULL, MODIFY height DECIMAL(10,4) NULL');

        $now = now();

        foreach (self::BOARDS as $boardName => $productName) {
            $board = DB::table('materials')->where('name', $boardName)->whereNotNull('sheet_width')->first();
            if (! $board) {
                continue;
            }
            DB::table('materials')->where('id', $board->id)->update(['sheet_kind' => 'board', 'inventory_type' => 'discrete', 'updated_at' => $now]);

            $sizes = self::BOARD_SIZES;
            $sizes[] = ['Whole board (' . $this->num($board->sheet_width) . ' x ' . $this->num($board->sheet_height) . ' ft)',
                (float) $board->sheet_width, (float) $board->sheet_height, 'ft', 'WHOLE'];
            $pieces = [];
            foreach ($sizes as [$name, $w, $h, $unit, $tag]) {
                $piece = $this->piece($board, $name, $w * ($unit === 'ft' ? 12 : 1), $h * ($unit === 'ft' ? 12 : 1), 'pcs', $tag, $now);
                if ($piece) {
                    $pieces[] = $piece;
                }
            }

            $this->sizeTypes($productName, [$board->id], array_map(fn ($p) => [$p['name'], $p['w'], $p['h']], $pieces), $now);
        }

        $rollIds = [];
        foreach (self::ROLLS as $rollName) {
            $roll = DB::table('materials')->where('name', $rollName)->whereNotNull('sheet_width')->first();
            if (! $roll) {
                continue;
            }
            DB::table('materials')->where('id', $roll->id)->update(['sheet_kind' => 'roll', 'updated_at' => $now]);
            // One foot of the roll, at full width.
            $this->piece($roll, 'per foot', (float) $roll->sheet_width * 12, 12, 'ft', 'FT', $now);
            $rollIds[] = $roll->id;
        }
        if ($rollIds) {
            $this->sizeTypes('Tarpaulin', $rollIds,
                array_map(fn ($s) => [$s[0] . ' x ' . $s[1] . ' ft', $s[0] * 12, $s[1] * 12], self::TARP_SIZES), $now);
        }
    }

    public function down(): void {
        $pieceIds = DB::table('materials')->whereNotNull('cut_from_id')->pluck('id');
        $used = DB::table('inventory_batches')->whereIn('material_id', $pieceIds)->count()
            + DB::table('sale_items')->whereNotNull('cut_material_id')->count();
        if ($used > 0) {
            throw new RuntimeException('Boards or rolls have been cut, or pieces sold. Their history would be lost: archive instead of rolling back.');
        }

        // The cut items go back to one "Standard" type, cut by size from their board or rolls.
        foreach (array_merge(array_values(self::BOARDS), ['Tarpaulin']) as $productName) {
            $product = DB::table('products')->where('name', $productName)->where('status', '!=', 'archived')->first();
            if (! $product) {
                continue;
            }
            $types = DB::table('product_variants')->where('product_id', $product->id)->pluck('id');
            if (DB::table('sale_items')->whereIn('product_variant_id', $types)->exists()) {
                throw new RuntimeException("{$productName} has been sold in its sizes. Archive instead of rolling back.");
            }
            $stocks = DB::table('variant_materials')->whereIn('product_variant_id', $types)->distinct()->pluck('material_id');
            DB::table('product_variants')->whereIn('id', $types)->delete();
            $standard = DB::table('product_variants')->insertGetId([
                'product_id' => $product->id, 'variant_name' => 'Standard', 'sku' => $product->sku . '-STANDARD',
                'stock_quantity' => 0, 'low_stock_threshold' => 0, 'reorder_point' => 0, 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($stocks as $materialId) {
                DB::table('variant_materials')->insert([
                    'product_variant_id' => $standard, 'material_id' => $materialId, 'quantity_per_unit' => 1,
                    'consumption_type' => 'per_area', 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        DB::table('materials')->whereIn('id', $pieceIds)->delete();
        DB::table('materials')->whereIn('name', array_keys(self::BOARDS))->update(['inventory_type' => 'continuous']);

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cut_material_id');
            $table->dropColumn('cut_blocks');
        });
        DB::statement('ALTER TABLE sale_items MODIFY width DECIMAL(8,2) NULL, MODIFY height DECIMAL(8,2) NULL');
        Schema::table('materials', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cut_from_id');
            $table->dropColumn(['sheet_kind', 'cut_width_in', 'cut_height_in', 'cut_yield']);
        });
    }

    // A cut size of a board or roll: its own discrete material holding the cut pieces. Skipped if it
    // doesn't fit. w and h are in inches.
    private function piece(object $stock, string $size, float $w, float $h, string $unit, string $tag, $now): ?array {
        $yield = $this->yieldOf((float) $stock->sheet_width * 12, (float) $stock->sheet_height * 12, $w, $h);
        if ($yield < 1) {
            return null;
        }

        $name = $stock->name . ' - ' . $size;
        if (! DB::table('materials')->where('name', $name)->exists()) {
            DB::table('materials')->insert([
                'name' => $name,
                'code' => $this->freeCode(substr($stock->code . $tag, 0, 10)),
                'unit' => $unit,
                'inventory_type' => 'discrete',
                'stock_quantity' => 0,
                'low_stock_threshold' => 0,
                'cut_from_id' => $stock->id,
                'cut_width_in' => $w,
                'cut_height_in' => $h,
                'cut_yield' => $yield,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        return ['name' => $size, 'w' => $w, 'h' => $h];
    }

    // How many w x h pieces one W x H board gives, cut in a grid whichever way round fits more.
    // Same rule as App\Services\Inventory\SheetYield::perSheet().
    private function yieldOf(float $W, float $H, float $w, float $h): int {
        $fit = fn ($a, $b) => (int) floor($a / $b + 1e-9);
        return max($fit($W, $w) * $fit($H, $h), $fit($W, $h) * $fit($H, $w));
    }

    // The item's types become its sizes (each cut by size from its stock), plus "Custom size".
    // A "Standard" type nothing was sold under is replaced.
    private function sizeTypes(string $productName, array $stockIds, array $sizes, $now): void {
        $product = DB::table('products')->where('name', $productName)->where('status', '!=', 'archived')->first();
        if (! $product) {
            return;
        }

        $standard = DB::table('product_variants')->where('product_id', $product->id)->where('variant_name', 'Standard')->value('id');
        if ($standard && ! DB::table('sale_items')->where('product_variant_id', $standard)->exists()) {
            DB::table('product_variants')->where('id', $standard)->delete();   // its recipe lines go with it
        }

        $sizes[] = ['Custom size', null, null];
        foreach ($sizes as [$name, $w, $h]) {
            if (DB::table('product_variants')->where('product_id', $product->id)->where('variant_name', $name)->exists()) {
                continue;
            }
            $typeId = DB::table('product_variants')->insertGetId([
                'product_id' => $product->id,
                'variant_name' => $name,
                'sku' => $this->freeSku($product->sku . '-' . strtoupper(preg_replace('/[^A-Za-z0-9]+/', '-', trim(preg_replace('/\(.*\)/', '', $name))))),
                'attributes' => json_encode($w ? ['width_in' => $w, 'height_in' => $h] : ['custom' => true]),
                'price' => null,
                'stock_quantity' => 0,
                'low_stock_threshold' => 0,
                'reorder_point' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($stockIds as $stockId) {
                DB::table('variant_materials')->insert([
                    'product_variant_id' => $typeId, 'material_id' => $stockId, 'quantity_per_unit' => 1,
                    'consumption_type' => 'per_area', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    private function num(float $n): string {
        return rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');
    }

    // Same rule as BatchInventoryService::ensureCode: the code, then CODE2, CODE3...
    private function freeCode(string $base): string {
        $code = $base;
        for ($i = 2; DB::table('materials')->where('code', $code)->exists(); $i++) {
            $code = substr($base, 0, 10 - strlen((string) $i)) . $i;
        }
        return $code;
    }

    private function freeSku(string $sku): string {
        $sku = rtrim($sku, '-');
        $free = $sku;
        for ($i = 2; DB::table('product_variants')->where('sku', $free)->exists(); $i++) {
            $free = $sku . '-' . $i;
        }
        return $free;
    }
};
