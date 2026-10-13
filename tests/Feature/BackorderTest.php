<?php
namespace Tests\Feature;

use App\Models\BatchConsumption;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\Inventory\BatchInventoryService;
use App\Services\Inventory\SheetYield;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\UsesTwoFactor;
use Tests\TestCase;

/**
 * Marcomedia accepts orders even when an item is out of stock (BackorderService), and the sintra
 * board and tarpaulin price lists (2026_10_11_000002). Run as a cashier would: through Billing/POS,
 * Sales and Restock. Needs MySQL or MariaDB, like BatchInventoryTest.
 */
class BackorderTest extends TestCase
{
    use RefreshDatabase, UsesTwoFactor;

    private BatchInventoryService $inventory;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('These tests need MySQL or MariaDB.');
        }

        $this->inventory = app(BatchInventoryService::class);
        $this->cashier = User::create([
            'name' => 'Cashier', 'email' => uniqid('cashier') . '@example.com', 'password' => 'not-used', 'role' => 'cashier',
        ]);
        $this->actingAs($this->cashier);
    }

    public function test_an_item_out_of_stock_still_sells_and_goes_on_order(): void
    {
        [$mug, $type, $blank] = $this->mug(1);

        $response = $this->post(route('pos.store'), $this->sale($mug, $type, 2));

        $sale = Sale::latest('id')->first();
        $response->assertRedirect(route('pos.receipt', $sale));
        $response->assertSessionHas('success', fn ($message) => str_contains($message, '2 items are on order'));
        $this->assertSame('processing', $sale->status);

        // Only one blank for two mugs: the whole line goes on order and takes nothing. The blank
        // stays for the next sale.
        $line = $sale->items->first();
        $this->assertTrue($line->isOnOrder());
        $this->assertSame(0, BatchConsumption::where('sale_item_id', $line->id)->count());
        $this->assertQty(1, $blank->fresh()->stock_quantity);

        $this->get(route('pos.receipt', $sale))->assertOk()
            ->assertSee('On order')
            ->assertSee('Marcomedia accepts orders even when items are out of stock');
    }

    public function test_lines_take_their_stock_in_cart_order(): void
    {
        [$mug, $type] = $this->mug(1);

        $this->post(route('pos.store'), [
            'items' => [
                ['product_id' => $mug->id, 'product_variant_id' => $type->id, 'quantity' => 1, 'unit_price' => 150, 'customization_details' => 'Ana'],
                ['product_id' => $mug->id, 'product_variant_id' => $type->id, 'quantity' => 1, 'unit_price' => 150, 'customization_details' => 'Ben'],
            ],
            'payment_method' => 'cash',
            'amount_paid' => 300,
        ]);

        $lines = Sale::latest('id')->first()->items->sortBy('id')->values();
        $this->assertSame([false, true], $lines->map->isOnOrder()->all());
    }

    public function test_a_restock_fills_the_orders_oldest_first_and_one_still_short_waits(): void
    {
        [$mug, $type, $blank] = $this->mug();
        $first = $this->sell($mug, $type, 2);
        $second = $this->sell($mug, $type, 1);

        // Two blanks come in: the first order takes both, the second still waits.
        $this->post(route('materials.restock', $blank), ['quantity' => 2])
            ->assertSessionHas('success', fn ($message) => str_contains($message, $first->invoice_number)
                && ! str_contains($message, $second->invoice_number));

        $line = $first->items->first()->fresh();
        $this->assertFalse($line->isOnOrder());
        $this->assertNotNull($line->backorder_filled_at);
        $this->assertQty(2, BatchConsumption::where('sale_item_id', $line->id)->sum('quantity_consumed'));
        $this->assertTrue($second->items->first()->fresh()->isOnOrder());
        $this->assertQty(0, $blank->fresh()->stock_quantity);

        $this->post(route('materials.restock', $blank), ['quantity' => 5]);

        $this->assertFalse($second->items->first()->fresh()->isOnOrder());
        $this->assertQty(4, $blank->fresh()->stock_quantity);
        $this->assertBalanced();
    }

    public function test_a_sale_cant_be_completed_while_an_item_is_on_order(): void
    {
        [$mug, $type, $blank] = $this->mug();
        $sale = $this->sell($mug, $type, 1);

        $this->post(route('sales.complete', $sale))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'still has items on order'));
        $this->patch(route('sales.update', $sale), ['status' => 'completed'])->assertSessionHas('error');
        $this->assertSame('processing', $sale->fresh()->status);

        // Stock that came in without a restock (straight into a batch): completing takes it first.
        $this->inventory->recordOpeningStock($blank, 1, 'store', $this->cashier->id);
        $this->post(route('sales.complete', $sale))->assertSessionHas('success');

        $this->assertSame('completed', $sale->fresh()->status);
        $this->assertQty(0, $blank->fresh()->stock_quantity);
        $this->assertBalanced();
    }

    public function test_take_from_stock_fills_a_sales_items_on_order(): void
    {
        [$mug, $type, $blank] = $this->mug();
        $sale = $this->sell($mug, $type, 1);

        $this->post(route('sales.take-stock', $sale))->assertSessionHas('error', fn ($message) => str_contains($message, 'Still on order'));

        $this->inventory->recordOpeningStock($blank, 1, 'store', $this->cashier->id);
        $this->post(route('sales.take-stock', $sale))->assertSessionHas('success');

        $this->assertFalse($sale->items->first()->fresh()->isOnOrder());
        $this->assertQty(0, $blank->fresh()->stock_quantity);
    }

    public function test_voiding_gives_back_only_what_the_sale_took_and_feeds_an_order_waiting(): void
    {
        [$mug, $type, $blank] = $this->mug(1);
        $taken = $this->sell($mug, $type, 1);       // takes the last blank
        $waiting = $this->sell($mug, $type, 1);     // on order
        $cancelled = $this->sell($mug, $type, 1);   // on order, then voided
        $this->assertFalse($taken->items->first()->isOnOrder());
        $this->assertTrue($waiting->items->first()->isOnOrder());

        // Voided by an owner (a cashier only asks: Approvals).
        $this->actingAs($this->owner())->post(route('sales.void', $cancelled))->assertSessionHas('success');
        // The blank the voided sale gives back goes to the order waiting.
        $this->post(route('sales.void', $taken))
            ->assertSessionHas('success', fn ($message) => str_contains($message, $waiting->invoice_number));

        $this->assertFalse($waiting->items->first()->fresh()->isOnOrder());
        $this->assertQty(0, $blank->fresh()->stock_quantity);
        // A made-to-order item has no count to give back: its blank came back instead.
        $this->assertSame(0, $type->fresh()->stock_quantity);

        // A voided sale never takes stock.
        $this->post(route('materials.restock', $blank), ['quantity' => 3]);
        $this->assertTrue($cancelled->items->first()->fresh()->isOnOrder());
        $this->assertQty(3, $blank->fresh()->stock_quantity);
        $this->assertBalanced();
    }

    public function test_a_size_cut_from_a_board_sells_on_order_and_the_board_restocked_is_cut_for_it(): void
    {
        $board = Material::create([
            'name' => uniqid('Test Board '), 'unit' => 'sq ft', 'inventory_type' => 'discrete', 'sheet_kind' => 'board',
            'sheet_width' => 4, 'sheet_height' => 8, 'pack_size' => 32, 'stock_quantity' => 0, 'low_stock_threshold' => 32,
        ]);
        $piece = Material::create([
            'name' => "{$board->name} - 8 x 8 in", 'unit' => 'pcs', 'inventory_type' => 'discrete', 'stock_quantity' => 0,
            'low_stock_threshold' => 0, 'cut_from_id' => $board->id, 'cut_width_in' => 8, 'cut_height_in' => 8,
            'cut_yield' => SheetYield::perSheet(48, 96, 8, 8),
        ]);
        $sign = $this->item('Test Sign');
        $size = $sign->variants()->create(['variant_name' => '8 inches', 'sku' => uniqid('V'), 'attributes' => ['width_in' => 8, 'height_in' => 8], 'price' => 650, 'stock_quantity' => 0, 'status' => 'active']);
        $custom = $sign->variants()->create(['variant_name' => 'Custom size', 'sku' => uniqid('V'), 'attributes' => ['custom' => true], 'stock_quantity' => 0, 'status' => 'active']);
        foreach ([$size, $custom] as $type) {
            $type->materials()->attach($board->id, ['quantity_per_unit' => 1, 'consumption_type' => 'per_area']);
        }

        $sale = $this->sell($sign, $size, 2);
        $line = $sale->items->first();
        $this->assertTrue($line->isOnOrder());
        $this->assertNull($line->cut_material_id);

        // Bigger than the board can never be made: that isn't taken as an order.
        $sales = Sale::count();
        $this->post(route('pos.store'), $this->sale($sign, $custom, 1, ['width' => 5, 'height' => 9]))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'bigger than any board'));
        $this->assertSame($sales, Sale::count());

        // A board comes in: it's cut into 72 pieces, and the order takes 2.
        $this->post(route('stock.restock-sheets', $board), ['sheets' => 1, 'location' => 'store'])
            ->assertSessionHas('success', fn ($message) => str_contains($message, $sale->invoice_number));

        $line->refresh();
        $this->assertFalse($line->isOnOrder());
        $this->assertSame([$piece->id, 1], [(int) $line->cut_material_id, (int) $line->cut_blocks]);
        $this->assertQty(70, $piece->fresh()->stock_quantity);
        $this->assertQty(0, $board->fresh()->stock_quantity);
        $this->assertBalanced();
    }

    public function test_a_stocked_item_out_of_stock_sells_on_order_and_takes_its_count_when_it_comes_in(): void
    {
        $shirt = $this->item('Test Shirt', true);
        $size = $shirt->variants()->create(['variant_name' => 'Medium', 'sku' => uniqid('V'), 'stock_quantity' => 1, 'status' => 'active']);

        $sale = $this->sell($shirt, $size, 2);
        $this->assertTrue($sale->items->first()->isOnOrder());
        $this->assertSame(1, $size->fresh()->stock_quantity);   // the one on hand stays for the next sale

        $this->post(route('inventory.adjust', $size), ['type' => 'stock_in', 'quantity' => 3]);
        $this->assertFalse($sale->items->first()->fresh()->isOnOrder());
        $this->assertSame(2, $size->fresh()->stock_quantity);

        // Voided (by an owner), the sale gives back the two it took.
        $this->actingAs($this->owner())->post(route('sales.void', $sale));
        $this->assertSame(4, $size->fresh()->stock_quantity);
    }

    public function test_the_sintra_board_and_tarpaulin_price_lists(): void
    {
        $this->assertSame([
            ['8 x 11 in', 200.0], ['12 x 16 in', 350.0], ['16 x 24 in', 750.0], ['8 inches', 650.0],
            ['10 inches', 800.0], ['12 inches', 950.0], ['15 inches', 1200.0], ['18 inches', 1500.0],
            ['20 inches', 2000.0], ['25 inches', 2600.0], ['30 inches', 3200.0], ['Custom size', null],
        ], $this->sizes('Sintra Board 5mm'));
        $this->assertSame([
            ['8 inches', 550.0], ['10 inches', 600.0], ['12 inches', 780.0], ['15 inches', 950.0],
            ['18 inches', 1250.0], ['20 inches', 1400.0], ['25 inches', 1800.0], ['30 inches', 2500.0], ['Custom size', null],
        ], $this->sizes('Sintra Board 3mm'));
        $this->assertFalse(Product::where('name', 'Sintra Board')->exists());

        // The 3mm is listed right after the 5mm.
        $order = Product::where('status', 'active')->inShopOrder()->pluck('name')->values();
        $this->assertSame($order->search('Sintra Board 5mm') + 1, $order->search('Sintra Board 3mm'));

        // Each size is a cut size of its own 4 x 8 ft board, with its yield. The old sizes are gone.
        $this->assertEquals([
            'Whole board (4 x 8 ft)' => 1, '8 x 11 in' => 48, '12 x 16 in' => 24, '16 x 24 in' => 12, '8 x 8 in' => 72,
            '10 x 10 in' => 36, '12 x 12 in' => 32, '15 x 15 in' => 18, '18 x 18 in' => 10, '20 x 20 in' => 8,
            '25 x 25 in' => 3, '30 x 30 in' => 3,
        ], $this->cutSizes('Sintra Board 5mm'));
        $this->assertEquals([
            '8 x 8 in' => 72, '10 x 10 in' => 36, '12 x 12 in' => 32, '15 x 15 in' => 18, '18 x 18 in' => 10,
            '20 x 20 in' => 8, '25 x 25 in' => 3, '30 x 30 in' => 3, 'Whole board (4 x 8 ft)' => 1,
        ], $this->cutSizes('Sintra Board 3mm'));

        // Every tarpaulin size on the flyer (2 to 6 ft by 3 to 10 ft), down each column, then "Custom size".
        $tarp = $this->sizes('Tarpaulin');
        $this->assertCount(41, $tarp);
        $this->assertSame([['2 x 3 ft', 232.0], ['2 x 10 ft', 540.0], ['3 x 3 ft', 298.0], ['6 x 10 ft', 1320.0], ['Custom size', null]],
            [$tarp[0], $tarp[7], $tarp[8], $tarp[39], $tarp[40]]);
        // Each printed on whichever of the four rolls fits it best.
        $rolls = ProductVariant::whereHas('product', fn ($q) => $q->where('name', 'Tarpaulin'))
            ->withCount('materials')->pluck('materials_count')->unique()->values()->all();
        $this->assertSame([4], $rolls);
    }

    public function test_billing_sales_and_products_pages_show_orders_and_prices(): void
    {
        [$mug, $type] = $this->mug();
        $sale = $this->sell($mug, $type, 1);

        $this->get(route('pos.index'))->assertOk()
            ->assertSee('Sintra Board 3mm')
            ->assertSee('out of stock, on order');
        $this->get(route('sales.index'))->assertOk()->assertSee('1 on order');
        $this->get(route('sales.index', ['status' => 'on_order']))->assertOk()->assertSee($sale->invoice_number);
        $this->get(route('inventory.index', ['search' => 'Tarpaulin']))->assertOk()->assertSee('₱1,320.00', false);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    // A made-to-order mug that takes one blank each, with $blanks in the store: [item, type, blank].
    // An owner, who voids sales (with two-step verification, which owners must have).
    private function owner(): User
    {
        return $this->withTwoFactor(User::create([
            'name' => 'Owner', 'email' => uniqid('admin') . '@example.com', 'password' => 'not-used', 'role' => 'admin',
        ]));
    }

    private function mug(float $blanks = 0): array
    {
        $blank = Material::create([
            'name' => uniqid('Mug Blank '), 'unit' => 'pcs', 'inventory_type' => 'discrete',
            'stock_quantity' => 0, 'low_stock_threshold' => 0, 'cost_per_unit' => 50,
        ]);
        if ($blanks > 0) {
            $this->inventory->recordOpeningStock($blank, $blanks, 'store', $this->cashier->id);
        }
        $mug = $this->item('Test Mug');
        $type = $mug->variants()->create(['variant_name' => 'Standard', 'sku' => uniqid('V'), 'stock_quantity' => 0, 'status' => 'active']);
        $type->materials()->attach($blank->id, ['quantity_per_unit' => 1, 'consumption_type' => 'fixed']);

        return [$mug, $type, $blank];
    }

    private function item(string $name, bool $stocked = false): Product
    {
        return Product::create([
            'name' => $name, 'sku' => uniqid('P'), 'base_price' => 150, 'has_variants' => true,
            'track_inventory' => $stocked, 'status' => 'active',
        ]);
    }

    // The Billing/POS form for one line, paid in full.
    private function sale(Product $item, ProductVariant $type, int $quantity, array $line = []): array
    {
        return [
            'items' => [$line + ['product_id' => $item->id, 'product_variant_id' => $type->id, 'quantity' => $quantity, 'unit_price' => 150]],
            'payment_method' => 'cash',
            'amount_paid' => 150 * $quantity,
        ];
    }

    private function sell(Product $item, ProductVariant $type, int $quantity): Sale
    {
        $this->post(route('pos.store'), $this->sale($item, $type, $quantity))->assertSessionHas('success');

        return Sale::with('items')->latest('id')->firstOrFail();
    }

    // An item's sizes offered at Billing/POS, in order: [name, price].
    private function sizes(string $item): array
    {
        return ProductVariant::whereHas('product', fn ($q) => $q->where('name', $item)->where('status', 'active'))
            ->where('status', 'active')->inShopOrder()->get()
            ->map(fn ($t) => [$t->variant_name, $t->price === null ? null : (float) $t->price])->all();
    }

    // A board's cut sizes in use: label => pieces from one board.
    private function cutSizes(string $board): array
    {
        return Material::with('cutFrom')->where('cut_from_id', Material::where('name', $board)->value('id'))->whereNull('archived_at')
            ->get()->mapWithKeys(fn ($piece) => [$piece->cutLabel() => $piece->cut_yield])->all();
    }

    private function assertQty(float $expected, $actual): void
    {
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.0005);
    }

    private function assertBalanced(): void
    {
        $this->assertSame([], $this->inventory->auditBatches());
    }
}
