<?php
namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Forms that were refused without saying why, Add Product saving what it shouldn't, and a login
 * that ended while Billing/POS sat open. Needs MySQL or MariaDB.
 */
class FormsAndSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('These tests need MySQL or MariaDB.');
        }

        $this->owner = User::create(['name' => 'Owner', 'email' => uniqid('admin') . '@example.com', 'password' => bcrypt('secret-password'), 'role' => 'admin']);
    }

    public function test_a_refused_add_product_comes_back_open_with_what_was_typed_and_why(): void
    {
        $taken = Product::create(['name' => 'Test Cap', 'sku' => 'TEST-CAP', 'base_price' => 100, 'has_variants' => true, 'track_inventory' => true, 'status' => 'active']);
        $taken->variants()->create(['variant_name' => 'Free size', 'sku' => 'TEST-CAP-FREE', 'stock_quantity' => 0, 'status' => 'active']);

        $page = $this->actingAs($this->owner)->from(route('inventory.index'))->followingRedirects()
            ->post(route('inventory.store'), $this->addProduct([
                'variants' => [['variant_name' => 'Big', 'sku' => 'TEST-CAP-FREE'], ['variant_name' => 'Small', 'sku' => 'TEST-MUG-S']],
            ]))->assertOk();

        // Nothing saved: not the product, not the other size.
        $this->assertFalse(Product::where('sku', 'TEST-MUG')->exists());
        $this->assertSame(1, DB::table('product_variants')->whereIn('sku', ['TEST-CAP-FREE', 'TEST-MUG-S'])->count());

        $page->assertSee("The product wasn't saved", false)
            ->assertSee('The SKU TEST-CAP-FREE is already used by another size.')
            ->assertSee('addingProduct: true', false)
            ->assertSee("name: 'Test Mug'", false)
            ->assertSee('TEST-MUG-S', false);
        // Said once: in the form, not again at the top of the page.
        $this->assertSame(1, substr_count($page->getContent(), 'is already used by another size.'));
    }

    public function test_sizes_unticked_before_saving_are_not_saved(): void
    {
        $this->actingAs($this->owner)->post(route('inventory.store'), $this->addProduct([
            'has_variants' => null,
            'variants' => [['variant_name' => 'Big', 'sku' => 'TEST-MUG-BIG']],
        ]))->assertSessionHas('success');

        $mug = Product::where('sku', 'TEST-MUG')->firstOrFail();
        $this->assertFalse($mug->has_variants);
        $this->assertSame(0, $mug->variants()->count());
    }

    public function test_ticking_sizes_without_adding_any_is_refused(): void
    {
        $this->actingAs($this->owner)->post(route('inventory.store'), $this->addProduct(['variants' => []]))
            ->assertSessionHasErrors('variants');
        $this->assertFalse(Product::where('sku', 'TEST-MUG')->exists());
    }

    public function test_a_material_listed_twice_on_a_size_is_one_line(): void
    {
        $ink = Material::create(['name' => 'Test Ink ' . uniqid(), 'unit' => 'ml', 'inventory_type' => 'discrete', 'stock_quantity' => 0, 'low_stock_threshold' => 0]);

        $this->actingAs($this->owner)->post(route('inventory.store'), $this->addProduct([
            'variants' => [['variant_name' => 'Big', 'sku' => 'TEST-MUG-BIG', 'materials' => [
                ['material_id' => $ink->id, 'quantity_per_unit' => 2], ['material_id' => $ink->id, 'quantity_per_unit' => 3], ['material_id' => '', 'quantity_per_unit' => ''],
            ]]],
        ]))->assertSessionHas('success');

        $lines = Product::where('sku', 'TEST-MUG')->firstOrFail()->variants()->firstOrFail()->materials;
        $this->assertSame([[$ink->id, 5.0]], $lines->map(fn ($m) => [$m->id, (float) $m->pivot->quantity_per_unit])->all());
    }

    public function test_a_new_category_with_an_archived_categorys_name_brings_it_back(): void
    {
        $banners = Category::create(['name' => 'Test Banners', 'slug' => 'test-banners', 'archived_at' => now()]);

        $this->actingAs($this->owner)->post(route('inventory.store'), $this->addProduct(['new_category_name' => 'Test Banners']))
            ->assertSessionHas('success');

        $this->assertSame($banners->id, Product::where('sku', 'TEST-MUG')->value('category_id'));
        $this->assertNull($banners->fresh()->archived_at);
    }

    public function test_a_refused_add_category_comes_back_open_saying_why(): void
    {
        $page = $this->actingAs($this->owner)->from(route('inventory.categories'))->followingRedirects()
            ->post(route('categories.store'), ['name' => '!!!'])->assertOk();

        $page->assertSee('Use a name with letters or numbers.')->assertSee('adding: true', false);
        $this->assertSame(0, Category::where('slug', '')->count());
    }

    public function test_a_page_without_its_own_error_list_shows_why_a_form_was_refused(): void
    {
        $item = Product::create(['name' => 'Test Cap', 'sku' => 'TEST-CAP', 'base_price' => 100, 'has_variants' => true, 'track_inventory' => true, 'status' => 'active']);
        $size = $item->variants()->create(['variant_name' => 'Free size', 'sku' => 'TEST-CAP-FREE', 'stock_quantity' => 0, 'status' => 'active']);

        // Products: a restock of 0 (the Add Product form, which lists its own, isn't the one refused).
        $this->actingAs($this->owner)->from(route('inventory.index'))
            ->post(route('inventory.adjust', $size), ['type' => 'stock_in', 'quantity' => 0])->assertSessionHasErrors('quantity');
        $message = session('errors')->first('quantity');
        $page = $this->get(route('inventory.index'))->assertSee($message);
        $this->assertSame(1, substr_count($page->getContent(), e($message)));

        // Billing/POS says it itself: not twice.
        $this->from(route('pos.index'))->post(route('pos.store'), ['payment_method' => 'cash', 'amount_paid' => 10])->assertSessionHasErrors('items');
        $message = session('errors')->first();
        $page = $this->get(route('pos.index'))->assertSee($message);
        $this->assertSame(1, substr_count($page->getContent(), e($message)));
    }

    public function test_an_open_page_keeps_the_login_alive_and_hears_when_it_ended(): void
    {
        $this->actingAs($this->owner)->get(route('keep-alive'))->assertNoContent();
        $this->get(route('pos.index'))->assertSee(json_encode(route('keep-alive')), false)->assertSee('id="session-ended"', false);

        auth()->logout();
        $this->getJson(route('keep-alive'))->assertUnauthorized();
    }

    // ------------------------------------------------------------------

    // Add Product as the page sends it: sizes ticked, a stock count kept, with $changes on top.
    private function addProduct(array $changes = []): array
    {
        return array_filter($changes + [
            '_form' => 'add-product', 'name' => 'Test Mug', 'sku' => 'TEST-MUG', 'base_price' => 150,
            'track_inventory' => '1', 'has_variants' => '1',
            'variants' => [['variant_name' => 'Standard', 'sku' => 'TEST-MUG-STD']],
        ], fn ($value) => $value !== null);
    }
}
