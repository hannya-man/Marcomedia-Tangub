<?php
namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\UsesTwoFactor;
use Tests\TestCase;

/**
 * Add Product always asks what a product is made from, takes counted materials in whole pieces,
 * and makes SKUs from the names. Needs MySQL or MariaDB.
 */
class AddProductTest extends TestCase
{
    use RefreshDatabase, UsesTwoFactor;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('These tests need MySQL or MariaDB.');
        }

        $this->owner = $this->withTwoFactor(User::create(['name' => 'Owner', 'email' => uniqid('admin') . '@example.com', 'password' => bcrypt('secret-password'), 'role' => 'admin']));
    }

    public function test_skus_are_made_from_the_names_and_the_categorys_prefix(): void
    {
        $gifts = $this->category('TG');
        // One already has the SKU the name makes: the new one gets -2.
        Product::create(['name' => 'Old Wooden Frame', 'sku' => 'TG-TEST-WOODEN-FRAME', 'base_price' => 100, 'has_variants' => true, 'track_inventory' => false, 'status' => 'active', 'category_id' => $gifts->id]);

        $this->actingAs($this->owner)->post(route('inventory.store'), $this->form([
            'name' => 'Test Wooden Frame', 'category_id' => $gifts->id,
            'variants' => [['variant_name' => '4x6'], ['variant_name' => 'A4 / Large'], ['variant_name' => 'Gift', 'sku' => 'MY-OWN']],
        ]))->assertSessionHas('success');

        $frame = Product::where('name', 'Test Wooden Frame')->firstOrFail();
        $this->assertSame('TG-TEST-WOODEN-FRAME-2', $frame->sku);
        $this->assertSame(
            ['4x6' => 'TG-TEST-WOODEN-FRAME-2-4X6', 'A4 / Large' => 'TG-TEST-WOODEN-FRAME-2-A4-LARGE', 'Gift' => 'MY-OWN'],
            $frame->variants()->pluck('sku', 'variant_name')->all()
        );

        // A SKU typed in is kept; a new category has no prefix yet.
        $this->post(route('inventory.store'), $this->form(['name' => 'Test Desk Clock', 'sku' => 'CLOCK-1', 'has_variants' => null]))->assertSessionHas('success');
        $this->assertSame(['CLOCK-1', ['CLOCK-1-STANDARD']], [Product::where('name', 'Test Desk Clock')->value('sku'), Product::where('name', 'Test Desk Clock')->sole()->variants()->pluck('sku')->all()]);
        $this->post(route('inventory.store'), $this->form(['name' => 'Test Pebble Coaster', 'new_category_name' => 'Test Magnets ' . uniqid(), 'has_variants' => null]))->assertSessionHas('success');
        $this->assertSame('TEST-PEBBLE-COASTER', Product::where('name', 'Test Pebble Coaster')->value('sku'));
    }

    public function test_the_page_suggests_the_same_skus(): void
    {
        $gifts = $this->category('TG');

        $page = $this->actingAs($this->owner)->get(route('inventory.index'))->assertOk();
        $prefixes = $page->viewData('skuPrefixes');
        $this->assertSame('TG', $prefixes[(string) $gifts->id]);
        $this->assertContains('TG-SAMPLE', $page->viewData('takenSkus')['products']->all());
        $page->assertSee('What is it made from?')->assertSee('name="uses_materials"', false);
    }

    public function test_it_always_asks_what_its_made_from(): void
    {
        $this->actingAs($this->owner)->post(route('inventory.store'), $this->form(['uses_materials' => null]))
            ->assertSessionHasErrors(['uses_materials' => 'Say what it\'s made from: add its materials, or choose "No materials".']);

        // "It uses materials" with a size that has none.
        $blank = $this->material('pcs');
        $this->post(route('inventory.store'), $this->form(['uses_materials' => '1', 'variants' => [
            ['variant_name' => 'Small', 'materials' => [['material_id' => $blank->id, 'quantity_per_unit' => 1]]],
            ['variant_name' => 'Big', 'materials' => [['material_id' => '', 'quantity_per_unit' => '']]],
        ]]))->assertSessionHasErrors(['variants.1.materials' => 'Add what Big is made from, or choose "No materials".']);
        $this->assertFalse(Product::where('name', 'Test Plate')->exists());

        // "No materials": lines left in the form aren't saved.
        $this->post(route('inventory.store'), $this->form(['uses_materials' => '0', 'variants' => [
            ['variant_name' => 'Small', 'materials' => [['material_id' => $blank->id, 'quantity_per_unit' => 1]]],
        ]]))->assertSessionHas('success');
        $this->assertSame(0, Product::where('name', 'Test Plate')->sole()->variants()->sole()->materials()->count());
    }

    public function test_a_counted_material_takes_whole_pieces_and_a_measured_one_any_amount(): void
    {
        $blank = $this->material('pcs');
        $ink = $this->material('ml', 'continuous');

        $this->actingAs($this->owner)->post(route('inventory.store'), $this->form(['uses_materials' => '1', 'variants' => [
            ['variant_name' => 'Small', 'materials' => [['material_id' => $blank->id, 'quantity_per_unit' => 1.5]]],
        ]]))->assertSessionHasErrors(['variants.0.materials.0.quantity_per_unit' => "{$blank->name} is counted in pieces: enter a whole number for each Small."]);

        $this->post(route('inventory.store'), $this->form(['uses_materials' => '1', 'variants' => [
            ['variant_name' => 'Small', 'materials' => [['material_id' => $blank->id, 'quantity_per_unit' => 2], ['material_id' => $ink->id, 'quantity_per_unit' => 0.75]]],
        ]]))->assertSessionHas('success');

        $lines = Product::where('name', 'Test Plate')->sole()->variants()->sole()->materials->mapWithKeys(fn ($m) => [$m->id => (float) $m->pivot->quantity_per_unit])->all();
        $this->assertSame([$blank->id => 2.0, $ink->id => 0.75], $lines);
    }

    public function test_a_one_size_products_materials_go_on_its_standard_size(): void
    {
        $blank = $this->material('pcs');

        $this->actingAs($this->owner)->post(route('inventory.store'), $this->form([
            'has_variants' => null, 'uses_materials' => '1', 'low_stock_threshold' => 4, 'variants' => null,
            'materials' => [['material_id' => $blank->id, 'quantity_per_unit' => 1]],
        ]))->assertSessionHas('success');

        $size = Product::where('name', 'Test Plate')->sole()->variants()->sole();
        $this->assertSame(['Standard', 4], [$size->variant_name, (int) $size->low_stock_threshold]);
        $this->assertSame([$blank->id], $size->materials->pluck('id')->all());

        // Without a line it's refused, saying so plainly.
        $this->post(route('inventory.store'), $this->form(['name' => 'Test Bowl', 'has_variants' => null, 'uses_materials' => '1', 'variants' => null]))
            ->assertSessionHasErrors(['variants.0.materials' => 'Add what it\'s made from, or choose "No materials".']);
    }

    public function test_a_size_added_on_the_edit_page_gets_a_sku_from_the_names(): void
    {
        $item = Product::create(['name' => 'Test Cup', 'sku' => 'TG-TEST-CUP', 'base_price' => 100, 'has_variants' => true, 'track_inventory' => false, 'status' => 'active']);
        $small = $item->variants()->create(['variant_name' => 'Small', 'sku' => 'TG-TEST-CUP-SMALL', 'stock_quantity' => 0, 'status' => 'active']);
        $row = fn ($id, $name, $sku) => ['id' => $id, 'variant_name' => $name, 'sku' => $sku, 'price' => 100, 'low_stock_threshold' => 5, 'active' => 1, 'remove' => 0, 'materials' => []];

        $this->actingAs($this->owner)->put(route('inventory.update', $item), [
            'name' => $item->name, 'sku' => $item->sku, 'base_price' => 100, 'track_inventory' => 0,
            'variants' => [$row($small->id, 'Small', $small->sku), $row('', 'Extra Large', '')],
        ])->assertSessionHas('success');

        $this->assertSame('TG-TEST-CUP-EXTRA-LARGE', $item->variants()->where('variant_name', 'Extra Large')->value('sku'));
    }

    // ------------------------------------------------------------------

    // A category whose products' SKUs start with $prefix.
    private function category(string $prefix): Category
    {
        $category = Category::create(['name' => 'Test Gifts ' . uniqid(), 'slug' => uniqid('test-gifts-')]);
        Product::create(['name' => 'Sample ' . uniqid(), 'sku' => "{$prefix}-SAMPLE", 'base_price' => 100, 'has_variants' => true, 'track_inventory' => false, 'status' => 'active', 'category_id' => $category->id]);

        return $category;
    }

    private function material(string $unit, string $type = 'discrete'): Material
    {
        return Material::create(['name' => 'Test Material ' . uniqid(), 'unit' => $unit, 'inventory_type' => $type, 'stock_quantity' => 0, 'low_stock_threshold' => 0]);
    }

    // Add Product as the page sends it, with sizes and no SKUs typed in, and $changes on top.
    private function form(array $changes = []): array
    {
        return array_filter($changes + [
            '_form' => 'add-product', 'name' => 'Test Plate', 'sku' => '', 'base_price' => 150,
            'track_inventory' => '1', 'has_variants' => '1', 'uses_materials' => '0',
            'variants' => [['variant_name' => 'Small']],
        ], fn ($value) => $value !== null);
    }
}
