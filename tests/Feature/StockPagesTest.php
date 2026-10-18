<?php
namespace Tests\Feature;

use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use App\Services\Inventory\BatchInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Restock box on Products for a size made from materials, and adding a material on the
 * Continuous and Discrete pages. Needs MySQL or MariaDB, like BatchInventoryTest.
 */
class StockPagesTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('These tests need MySQL or MariaDB.');
        }

        $this->cashier = User::create(['name' => 'Cashier', 'email' => 'cashier@example.com', 'password' => 'not-used', 'role' => 'cashier']);
        $this->actingAs($this->cashier);
    }

    public function test_restocking_a_stocked_size_short_of_its_materials_says_so_and_adds_nothing(): void
    {
        $blank = Material::create(['name' => 'Acrylic Blank', 'unit' => 'pcs', 'inventory_type' => 'discrete', 'stock_quantity' => 0, 'low_stock_threshold' => 0]);
        app(BatchInventoryService::class)->recordOpeningStock($blank, 2, 'store', $this->cashier->id);
        $item = Product::create(['name' => 'Acrylic Stand', 'sku' => 'AS-1', 'base_price' => 80, 'has_variants' => true, 'track_inventory' => true, 'status' => 'active']);
        $size = $item->variants()->create(['variant_name' => 'Small', 'sku' => 'AS-1-S', 'price' => 80, 'stock_quantity' => 0, 'status' => 'active']);
        $size->materials()->attach($blank->id, ['quantity_per_unit' => 1, 'consumption_type' => 'fixed']);

        // Five stands need five blanks; the store has two. This was a server error page.
        $this->post(route('inventory.adjust', $size), ['type' => 'stock_in', 'quantity' => 5])
            ->assertRedirect()
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'Stock not added to Small')
                && str_contains($message, 'Not enough Acrylic Blank in the store: need 5 pcs, have 2'));
        $this->assertSame(0, (int) $size->fresh()->stock_quantity);
        $this->assertEqualsWithDelta(2, (float) $blank->fresh()->stock_quantity, 0.0005);

        // Two can be made, and making them uses the two blanks.
        $this->post(route('inventory.adjust', $size), ['type' => 'stock_in', 'quantity' => 2])->assertSessionHas('success');
        $this->assertSame(2, (int) $size->fresh()->stock_quantity);
        $this->assertEqualsWithDelta(0, (float) $blank->fresh()->stock_quantity, 0.0005);
    }

    public function test_a_material_needs_a_name_no_other_material_has(): void
    {
        $add = fn (array $fields) => $this->post(route('stock.store'), $fields + ['inventory_type' => 'discrete', 'unit' => 'pcs']);

        $add(['name' => 'Glossy Paper', 'opening_stock' => 10])->assertSessionHas('success');

        // Capitals and spaces don't make it a different material, on either page or as a board.
        $add(['name' => '  glossy   PAPER '])
            ->assertSessionHas('error', "There's already a material called Glossy Paper on Discrete Materials. Restock that one instead.");
        $add(['name' => 'Glossy Paper', 'inventory_type' => 'continuous', 'unit' => 'sheets'])->assertSessionHas('error');
        $add(['name' => 'Glossy Paper', 'sheet' => 1, 'sheet_width' => 4, 'sheet_height' => 8])->assertSessionHas('error');
        $this->assertSame(1, Material::where('name', 'Glossy Paper')->count());

        // Archived, it's brought back from the archive, not added again.
        $this->delete(route('materials.destroy', Material::where('name', 'Glossy Paper')->first()));
        $add(['name' => 'Glossy Paper'])
            ->assertSessionHas('error', 'Glossy Paper is already in the Material archive. Restore it there instead of adding it again.');

        // A new name is fine.
        $add(['name' => 'Matte Paper'])->assertSessionHas('success');
    }
}
