<?php
namespace Tests\Feature;

use App\Models\BatchAdjustment;
use App\Models\BatchConsumption;
use App\Models\InventoryBatch;
use App\Models\Material;
use App\Models\MaterialOffcut;
use App\Models\ProductVariant;
use App\Models\SaleItem;
use App\Models\StockAlert;
use App\Models\Supplier;
use App\Services\Inventory\BatchInventoryService;
use App\Services\Inventory\InventoryException;
use App\Services\Inventory\LegacyStockConverter;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Batch inventory rules, end to end, on a real MySQL/MariaDB database
 * (the one-open-batch rule and the CHECK constraints live in the database).
 *
 * phpunit.xml:
 *   <env name="DB_CONNECTION" value="mysql"/>
 *   <env name="DB_DATABASE" value="marcomedia_test"/>
 * Run: php artisan test --filter=BatchInventoryTest
 */
class BatchInventoryTest extends TestCase
{
    use RefreshDatabase;

    private BatchInventoryService $inventory;
    private int $userId;
    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('These tests need MySQL or MariaDB.');
        }

        $this->inventory = app(BatchInventoryService::class);
        $this->userId = DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => uniqid('owner') . '@example.com', 'password' => 'not-used',
            'role' => 'admin', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->supplier = Supplier::create(['name' => 'CDO Print Supply', 'city' => 'Cagayan de Oro', 'lead_time_days' => 3]);
    }

    // ------------------------------------------------------------------
    // Purchase orders -> sealed batches
    // ------------------------------------------------------------------

    public function test_delivery_creates_one_sealed_batch_per_pack(): void
    {
        $cb = $this->material('Central Board', 'sheets', 100);
        $batches = $this->deliver($cb, 3, 100);

        $this->assertSame(['SEPT-2026-CB-1', 'SEPT-2026-CB-2', 'SEPT-2026-CB-3'], $batches->pluck('batch_number')->all());
        $this->assertSame(['unopened'], $batches->pluck('status')->unique()->values()->all());
        $this->assertSame('received', $batches->first()->purchaseOrderItem->purchaseOrder->status);
        $this->assertQty(300, $cb->fresh()->stock_quantity);

        // A new month starts a new sequence.
        $this->assertSame('OCT-2026-CB-1', $this->deliver($cb, 1, 100, 'warehouse', '2026-10-02')->first()->batch_number);
        $this->assertBalanced();
    }

    public function test_partial_delivery_over_delivery_and_cancelled_po(): void
    {
        $cb = $this->material('Central Board', 'sheets', 100);
        $po = $this->inventory->createPurchaseOrder($this->supplier, [
            ['material_id' => $cb->id, 'packs' => 5, 'qty_per_pack' => 100],
        ], $this->userId);
        $item = $po->items->first();

        $this->inventory->receive($item, 2, 'store', $this->userId);
        $this->assertSame('partial', $po->fresh()->status);

        $this->assertThrows(function () use ($item) {
            $this->inventory->receive($item, 4, 'store', $this->userId);
        }, 'only 3 pack(s)');

        $this->inventory->cancelPurchaseOrder($po, 'Supplier ran out');
        $this->assertThrows(function () use ($item) {
            $this->inventory->receive($item, 1, 'store', $this->userId);
        }, 'was cancelled');
        $this->assertQty(200, $cb->fresh()->stock_quantity);
    }

    public function test_material_codes_for_batch_numbers(): void
    {
        $this->assertSame('CB', Material::suggestCode('Central Board'));
        $this->assertSame('CMB11', Material::suggestCode('Ceramic Mug Blank (11oz)'));
        $this->assertSame('TARP', Material::suggestCode('Tarpaulin'));
        $this->assertSame('AS3', Material::suggestCode('Acrylic Sheet 3mm'));

        $this->assertSame('CB', $this->inventory->ensureCode($this->material('Central Board', 'sheets')));
        $this->assertSame('CB2', $this->inventory->ensureCode($this->material('Cardboard Box', 'pcs')));
    }

    // ------------------------------------------------------------------
    // Sir Jay's rule: one active batch
    // ------------------------------------------------------------------

    public function test_opening_a_new_batch_closes_the_old_one_and_leftover_must_be_explained(): void
    {
        $cb = $this->material('Central Board', 'sheets', 50);
        $this->deliver($cb, 2, 100);
        $b1 = $this->batch('SEPT-2026-CB-1');
        $b2 = $this->batch('SEPT-2026-CB-2');

        $this->inventory->openBatch($b1, $this->userId);
        $this->assertSame('open', $b1->fresh()->status);

        // 100 sheets can't just disappear.
        $this->assertThrows(function () use ($b2) {
            $this->inventory->openBatch($b2, $this->userId);
        }, 'still has 100 sheets left');
        $this->assertSame('open', $b1->fresh()->status);

        $this->inventory->openBatch($b2, $this->userId, ['write_off_reason' => 'Pack got wet']);

        $b1 = $b1->fresh();
        $this->assertSame('closed', $b1->status);
        $this->assertSame('replaced', $b1->close_reason);
        $this->assertQty(0, $b1->remaining_quantity);
        $this->assertSame('open', $b2->fresh()->status);
        $this->assertSame(1, InventoryBatch::where('material_id', $cb->id)->where('status', 'open')->count());
        $this->assertQty(-100, BatchAdjustment::where('inventory_batch_id', $b1->id)->where('type', 'write_off')->value('quantity'));
        $this->assertQty(100, $cb->fresh()->stock_quantity);
        $this->assertBalanced();
    }

    public function test_database_itself_refuses_two_open_batches_and_bad_balances(): void
    {
        $cb = $this->material('Central Board', 'sheets');
        $this->deliver($cb, 2, 100);
        $this->inventory->openBatch($this->batch('SEPT-2026-CB-1'), $this->userId);

        // Raw UPDATEs on purpose: these skip the app, so only the database can stop them.
        $cb2 = DB::table('inventory_batches')->where('batch_number', 'SEPT-2026-CB-2');
        $this->assertTrue($this->rejectedByDatabase(function () use ($cb2) { (clone $cb2)->update(['status' => 'open']); }));
        $this->assertTrue($this->rejectedByDatabase(function () use ($cb2) { (clone $cb2)->update(['remaining_quantity' => -1]); }));
        $this->assertTrue($this->rejectedByDatabase(function () use ($cb2) { (clone $cb2)->update(['status' => 'closed']); }));
    }

    // ------------------------------------------------------------------
    // Deducting from the active batch
    // ------------------------------------------------------------------

    public function test_job_takes_from_active_batch_rolls_into_next_pack_and_is_traceable(): void
    {
        $cb = $this->material('Central Board', 'sheets', 20);
        $this->deliver($cb, 2, 100);

        $this->inventory->consumeForSaleItem($this->job([[$cb, 1, 'fixed']], 30), $this->userId);
        $b1 = $this->batch('SEPT-2026-CB-1');
        $this->assertSame('open', $b1->status);
        $this->assertSame('auto', $b1->open_method);   // nobody had opened a pack yet
        $this->assertQty(70, $b1->remaining_quantity);

        // 80 sheets: the last 70 of CB-1, then 10 from CB-2.
        $line = $this->job([[$cb, 1, 'fixed']], 80, null, null, 50);
        $rows = $this->inventory->consumeForSaleItem($line, $this->userId);

        $this->assertSame(['SEPT-2026-CB-1' => 70.0, 'SEPT-2026-CB-2' => 10.0], $this->takenFrom($rows));
        $b1 = $b1->fresh();
        $this->assertSame('closed', $b1->status);
        $this->assertSame('empty', $b1->close_reason);
        $this->assertSame('open', $this->batch('SEPT-2026-CB-2')->status);
        $this->assertQty(90, $cb->fresh()->stock_quantity);
        $this->assertQty(4000, BatchConsumption::where('sale_item_id', $line->id)->sum('revenue'));
        $this->assertBalanced();
    }

    public function test_store_shortage_blocks_the_whole_job_and_points_to_the_warehouse(): void
    {
        $tarp = $this->material('Tarpaulin 3ft', 'ft', 50);
        $this->deliver($tarp, 1, 100, 'store');
        $this->deliver($tarp, 1, 100, 'warehouse');

        $job = $this->job([[$tarp, 1, 'per_length']], 1, 3, 150);
        $this->assertThrows(function () use ($job) {
            $this->inventory->consumeForSaleItem($job, $this->userId);
        }, 'is in the warehouse');
        $this->assertQty(200, $tarp->fresh()->stock_quantity);
        $this->assertSame(0, BatchConsumption::count());

        $this->inventory->transferToStore($this->batch('SEPT-2026-T3-2'));
        $this->inventory->consumeForSaleItem($job, $this->userId);
        $this->assertQty(50, $tarp->fresh()->stock_quantity);
        $this->assertBalanced();
    }

    public function test_plaque_takes_each_material_from_its_own_active_batch(): void
    {
        $acrylic = $this->material('Acrylic Sheet 3mm', 'sq in', 0, ['cost_per_unit' => 0.65]);
        $base = $this->material('Wooden Plaque Base', 'pcs', 5, ['cost_per_unit' => 80]);
        $emblem = $this->material('Printed Emblem Sticker', 'pcs', 5, ['cost_per_unit' => 15]);
        $this->deliver($acrylic, 1, 4608);
        $this->deliver($base, 1, 20);
        $this->deliver($emblem, 1, 50);

        // 2 plaques, 8 x 10 in, 5% cutting allowance on acrylic.
        $item = $this->job([[$acrylic, 1.05, 'per_area'], [$base, 1, 'fixed'], [$emblem, 1, 'fixed']], 2, 8, 10, 450);
        $rows = $this->inventory->consumeForSaleItem($item, $this->userId);

        $this->assertSame(['SEPT-2026-AS3-1' => 168.0, 'SEPT-2026-PES-1' => 2.0, 'SEPT-2026-WPB-1' => 2.0], $this->takenFrom($rows));
        $this->assertQty(900, $rows->sum('revenue'));
        $this->assertBalanced();
    }

    public function test_tarp_job_uses_length_times_quantity(): void
    {
        $tarp = $this->material('Tarpaulin 3ft', 'ft', 20);
        $this->deliver($tarp, 1, 150);

        // Two 3 x 6 ft tarps from a 3 ft roll: 6 ft x 2 = 12 ft.
        $rows = $this->inventory->consumeForSaleItem($this->job([[$tarp, 1, 'per_length']], 2, 3, 6), $this->userId);
        $this->assertQty(12, $rows->sum('quantity_consumed'));

        $this->assertThrows(function () use ($tarp) {
            $this->inventory->consumeForSaleItem($this->job([[$tarp, 1, 'per_length']], 1), $this->userId);
        }, 'Enter the length');
    }

    public function test_finished_stock_uses_materials_at_production_not_at_sale(): void
    {
        $blank = $this->material('Ceramic Mug Blank (11oz)', 'pcs', 10);
        $this->deliver($blank, 1, 100);
        [$productId, $variantId] = $this->product(true, [[$blank, 1, 'fixed']]);
        $variant = ProductVariant::findOrFail($variantId);

        $variant->adjustStock(20, 'stock_in', 'production', null, 'Printed 20 mugs', $this->userId);
        $this->assertQty(80, $blank->fresh()->stock_quantity);
        $row = BatchConsumption::firstOrFail();
        $this->assertNotNull($row->inventory_movement_id);
        $this->assertNull($row->sale_item_id);

        // Not enough blanks: the run is refused and finished stock does not go up.
        $this->assertThrows(function () use ($variant) {
            $variant->fresh()->adjustStock(500, 'stock_in', 'production', null, null, $this->userId);
        }, 'Not enough Ceramic Mug Blank (11oz)');
        $this->assertSame(20, (int) $variant->fresh()->stock_quantity);

        // Selling a finished mug does not touch the blanks again.
        $this->assertCount(0, $this->inventory->consumeForSaleItem($this->saleItem($productId, $variantId, 1, 150), $this->userId));
        $this->assertQty(80, $blank->fresh()->stock_quantity);
    }

    // ------------------------------------------------------------------
    // Wastage, counts, voids, offcuts
    // ------------------------------------------------------------------

    public function test_recording_breakage_500_minus_5_keeps_the_batch_balanced(): void
    {
        $mug = $this->material('Ceramic Mug Blank (11oz)', 'pcs', 50);
        $batch = $this->deliver($mug, 1, 500)->first();
        $this->assertSame('SEPT-2026-CMB11-1', $batch->batch_number);

        $loss = $this->inventory->recordLoss($batch, 'damaged', 5, 'Broken in delivery from CDO', $this->userId);
        $this->assertQty(-5, $loss->quantity);
        $this->assertQty(500, $loss->quantity_before);
        $this->assertQty(495, $loss->quantity_after);
        $this->assertQty(495, $batch->fresh()->remaining_quantity);
        $this->assertQty(495, $mug->fresh()->stock_quantity);

        $this->assertThrows(function () use ($batch) {
            $this->inventory->recordLoss($batch, 'damaged', 2, ' ', $this->userId);
        }, 'Give a reason');
        $this->assertThrows(function () use ($batch) {
            $this->inventory->recordLoss($batch, 'damaged', 600, 'Dropped', $this->userId);
        }, 'only has 495');

        // Physical count finds 490: a -5 correction is logged. Same count again: nothing logged.
        $this->assertQty(-5, $this->inventory->recordCount($batch, 490, $this->userId)->quantity);
        $this->assertNull($this->inventory->recordCount($batch, 490, $this->userId));

        // Count of zero closes the batch as empty.
        $this->inventory->recordCount($batch, 0, $this->userId, 'Whole box missing');
        $this->assertSame('closed', $batch->fresh()->status);
        $this->assertBalanced();
    }

    public function test_void_puts_material_back_and_is_safe_to_run_twice(): void
    {
        $cb = $this->material('Central Board', 'sheets', 10);
        $this->deliver($cb, 2, 100);
        $this->inventory->consumeForSaleItem($this->job([[$cb, 1, 'fixed']], 30), $this->userId);
        $crossing = $this->job([[$cb, 1, 'fixed']], 80);
        $this->inventory->consumeForSaleItem($crossing, $this->userId);   // 70 from CB-1 (closes), 10 from CB-2

        $this->inventory->returnSaleItem($crossing, $this->userId);
        $this->inventory->returnSaleItem($crossing, $this->userId);       // second click does nothing

        $this->assertQty(170, $this->batch('SEPT-2026-CB-2')->remaining_quantity);   // 90 + 80 back on the open pile
        $this->assertSame(2, BatchAdjustment::where('type', 'void_return')->where('sale_item_id', $crossing->id)->count());
        $this->assertQty(170, $cb->fresh()->stock_quantity);
        $this->assertBalanced();
    }

    public function test_void_reopens_the_source_batch_when_nothing_is_open(): void
    {
        $cb = $this->material('Central Board', 'sheets', 10);
        $this->deliver($cb, 1, 100);
        $job = $this->job([[$cb, 1, 'fixed']], 100);
        $this->inventory->consumeForSaleItem($job, $this->userId);
        $this->assertSame('closed', $this->batch('SEPT-2026-CB-1')->status);

        $this->inventory->returnSaleItem($job, $this->userId);
        $b1 = $this->batch('SEPT-2026-CB-1');
        $this->assertSame('open', $b1->status);
        $this->assertQty(100, $b1->remaining_quantity);
        $this->assertBalanced();
    }

    public function test_acrylic_offcuts_are_saved_reused_and_not_counted_as_stock(): void
    {
        // Unit = square inches. One 48 x 96 in sheet = 4,608 sq in. 5% cutting allowance.
        $acrylic = $this->material('Acrylic Sheet 3mm', 'sq in', 2000);
        $this->deliver($acrylic, 2, 4608);
        $plaque = function () use ($acrylic) { return $this->job([[$acrylic, 1.05, 'per_area']], 1, 10, 12); };

        $this->inventory->consumeForSaleItem($plaque(), $this->userId);
        $this->assertQty(4608 - 126, $this->batch('SEPT-2026-AS3-1')->remaining_quantity);

        // Open the next sheet. Keep a 20 x 24 piece from the old one, write off the rest.
        $this->inventory->openBatch($this->batch('SEPT-2026-AS3-2'), $this->userId, [
            'offcuts' => [['width' => 20, 'height' => 24, 'quantity' => 480]],
            'write_off_reason' => 'Scraps too small to use',
        ]);
        $offcut = MaterialOffcut::firstOrFail();
        $this->assertSame('20 x 24 piece', $offcut->label);
        $this->assertQty(4608, $acrylic->fresh()->stock_quantity);   // offcuts are not counted as stock

        // A 10 x 12 plaque cut from the offcut: no sheet stock used.
        $fromOffcut = $plaque();
        $this->inventory->consumeForSaleItem($fromOffcut, $this->userId, [$offcut->id]);
        $this->assertSame('used', $offcut->fresh()->status);
        $this->assertSame(0, BatchConsumption::where('sale_item_id', $fromOffcut->id)->count());

        // Voiding that job frees the offcut. A 30 x 30 job is too big for it.
        $this->inventory->returnSaleItem($fromOffcut, $this->userId);
        $this->assertSame('available', $offcut->fresh()->status);
        $big = $this->job([[$acrylic, 1.05, 'per_area']], 1, 30, 30);
        $this->assertThrows(function () use ($big, $offcut) {
            $this->inventory->consumeForSaleItem($big, $this->userId, [$offcut->id]);
        }, 'too small');
        $this->assertBalanced();
    }

    // ------------------------------------------------------------------
    // Alerts
    // ------------------------------------------------------------------

    public function test_low_stock_alert_opens_once_updates_and_resolves_itself(): void
    {
        $tarp = $this->material('Tarpaulin 4ft', 'ft', 100, ['reorder_packs' => 2, 'default_supplier_id' => $this->supplier->id]);
        $this->deliver($tarp, 2, 100);
        $this->assertSame(0, StockAlert::active()->count());

        $this->inventory->consume($tarp, 120, [], $this->userId);
        $alert = StockAlert::active()->where('type', 'low_stock')->firstOrFail();
        $this->assertStringContainsString('80 ft left, reorder point is 100', $alert->message);
        $this->assertStringContainsString('Order 2 pack(s) from CDO Print Supply (Cagayan de Oro)', $alert->message);

        // More sales update the same alert instead of adding new ones.
        $this->inventory->consume($tarp, 10, [], $this->userId);
        $this->assertSame(1, StockAlert::where('material_id', $tarp->id)->where('type', 'low_stock')->count());
        $this->assertQty(70, $alert->fresh()->stock_level);

        // Ordering shows in the alert, so nobody orders twice.
        $po = $this->inventory->createPurchaseOrder($this->supplier, [
            ['material_id' => $tarp->id, 'packs' => 2, 'qty_per_pack' => 100],
        ], $this->userId);
        $this->assertStringContainsString("2 pack(s) already on order ({$po->po_number})", $alert->fresh()->message);

        // Delivery arrives: the alert resolves on its own.
        $this->inventory->receive($po->items->first(), 2, 'store', $this->userId);
        $alert = $alert->fresh();
        $this->assertSame('resolved', $alert->status);
        $this->assertNotNull($alert->resolved_at);
        $this->assertBalanced();
    }

    public function test_transfer_and_out_of_stock_alerts(): void
    {
        $ink = $this->material('Sublimation Ink', 'ml', 100);
        $this->deliver($ink, 1, 500, 'store');
        $this->deliver($ink, 1, 500, 'warehouse');

        // Using the store bottle opens it. The store has no sealed bottle left, the warehouse does.
        $this->inventory->consume($ink, 100, [], $this->userId);
        $transfer = StockAlert::active()->where('type', 'transfer_needed')->firstOrFail();
        $this->assertStringContainsString('last pack', $transfer->message);

        $this->inventory->transferToStore($this->batch('SEPT-2026-SI-2'));
        $this->assertSame('resolved', $transfer->fresh()->status);

        $this->inventory->consume($ink, 900, [], $this->userId);
        $this->assertSame(1, StockAlert::active()->where('type', 'out_of_stock')->count());
        $this->assertSame(0, StockAlert::active()->where('type', 'low_stock')->count());
        $this->assertBalanced();
    }

    // ------------------------------------------------------------------
    // Go-live conversion
    // ------------------------------------------------------------------

    public function test_legacy_stock_converts_into_opening_batches(): void
    {
        $paper = $this->material('Sublimation Transfer Paper', 'sheets', 50);
        DB::table('materials')->where('id', $paper->id)->update(['stock_quantity' => 500]);   // old typed-in stock
        $ink = $this->material('Sublimation Ink', 'ml', 300);
        DB::table('materials')->where('id', $ink->id)->update(['stock_quantity' => -104]);    // what the old seeder left

        $converter = app(LegacyStockConverter::class);
        $plan = collect($converter->plan())->keyBy('material');
        $this->assertSame('add opening batch', $plan['Sublimation Transfer Paper']['action']);
        $this->assertSame('reset negative stock', $plan['Sublimation Ink']['action']);

        $converter->apply($this->userId);
        $this->assertQty(500, $paper->fresh()->stock_quantity);
        $this->assertSame(1, InventoryBatch::where('material_id', $paper->id)->whereNull('purchase_order_item_id')->count());
        $this->assertQty(0, $ink->fresh()->stock_quantity);
        $this->assertSame(['ok'], collect($converter->plan())->pluck('action')->unique()->values()->all());
        $this->assertBalanced();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function material(string $name, string $unit, float $reorderPoint = 0, array $extra = []): Material
    {
        return Material::create($extra + [
            'name' => $name, 'unit' => $unit, 'stock_quantity' => 0,
            'low_stock_threshold' => $reorderPoint, 'cost_per_unit' => 10,
        ]);
    }

    // PO, then delivery of all packs. Returns the new batches.
    private function deliver(Material $material, int $packs, float $perPack, string $location = 'store', string $date = '2026-09-03'): Collection
    {
        $po = $this->inventory->createPurchaseOrder($this->supplier, [
            ['material_id' => $material->id, 'packs' => $packs, 'qty_per_pack' => $perPack, 'cost_per_pack' => $perPack * 10],
        ], $this->userId, null, null, $date);

        return $this->inventory->receive($po->items->first(), $packs, $location, $this->userId, $date);
    }

    // [productId, variantId]. $bom: [[Material, quantity_per_unit, consumption_type], ...]
    private function product(bool $trackInventory, array $bom): array
    {
        $productId = DB::table('products')->insertGetId([
            'name' => 'Test product', 'sku' => uniqid('P'), 'base_price' => 100, 'has_variants' => 1,
            'track_inventory' => $trackInventory ? 1 : 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $variantId = DB::table('product_variants')->insertGetId([
            'product_id' => $productId, 'variant_name' => 'Standard', 'sku' => uniqid('V'),
            'stock_quantity' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($bom as [$material, $perUnit, $type]) {
            DB::table('variant_materials')->insert([
                'product_variant_id' => $variantId, 'material_id' => $material->id, 'quantity_per_unit' => $perUnit,
                'consumption_type' => $type, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return [$productId, $variantId];
    }

    private function saleItem(int $productId, int $variantId, int $qty, float $price, ?float $width = null, ?float $height = null): SaleItem
    {
        $saleId = DB::table('sales')->insertGetId([
            'invoice_number' => uniqid('INV'), 'user_id' => $this->userId, 'subtotal' => $price * $qty,
            'total_amount' => $price * $qty, 'amount_paid' => $price * $qty, 'payment_method' => 'cash',
            'status' => 'processing', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $itemId = DB::table('sale_items')->insertGetId([
            'sale_id' => $saleId, 'product_id' => $productId, 'product_variant_id' => $variantId, 'item_name' => 'Test job',
            'quantity' => $qty, 'unit_price' => $price, 'subtotal' => $price * $qty, 'width' => $width, 'height' => $height,
        ]);

        return SaleItem::findOrFail($itemId);
    }

    // A made-to-order line: product with Track inventory OFF.
    private function job(array $bom, int $qty = 1, ?float $width = null, ?float $height = null, float $price = 100): SaleItem
    {
        [$productId, $variantId] = $this->product(false, $bom);

        return $this->saleItem($productId, $variantId, $qty, $price, $width, $height);
    }

    private function batch(string $number): InventoryBatch
    {
        return InventoryBatch::where('batch_number', $number)->firstOrFail();
    }

    private function takenFrom(Collection $rows): array
    {
        return $rows->mapWithKeys(function ($row) {
            return [$row->batch->batch_number => (float) $row->quantity_consumed];
        })->sortKeys()->all();
    }

    private function rejectedByDatabase(callable $query): bool
    {
        try {
            $query();
        } catch (QueryException $e) {
            return true;
        }

        return false;
    }

    private function assertThrows(callable $action, string $messageContains): void
    {
        try {
            $action();
        } catch (InventoryException $e) {
            $this->assertStringContainsString($messageContains, $e->getMessage());
            return;
        }
        $this->fail("Expected an InventoryException containing \"{$messageContains}\".");
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
