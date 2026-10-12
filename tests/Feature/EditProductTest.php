<?php
namespace Tests\Feature;

use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The owner's Edit page for a product: its details, its sizes and their prices, and what each size
 * is made from. Needs MySQL or MariaDB.
 */
class EditProductTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('These tests need MySQL or MariaDB.');
        }

        $this->owner = $this->user('admin');
        $this->cashier = $this->user('cashier');
    }

    public function test_the_owner_has_an_edit_button_and_a_cashier_does_not(): void
    {
        [$item] = $this->item();
        $edit = route('inventory.edit', $item);

        $this->actingAs($this->owner)->get(route('inventory.index', ['search' => $item->name]))->assertOk()->assertSee($edit, false);
        $this->get($edit)->assertOk()->assertSee('Sizes and prices');

        $this->actingAs($this->cashier)->get(route('inventory.index', ['search' => $item->name]))->assertOk()->assertDontSee($edit, false);
        $this->get($edit)->assertRedirect(route('pos.index'));
        $this->put(route('inventory.update', $item), $this->form($item))->assertRedirect(route('pos.index'));
    }

    public function test_the_owner_sets_the_details_and_each_sizes_price(): void
    {
        [$item, $small, $large] = $this->item();
        $form = $this->form($item, ['name' => 'Test Keychain Deluxe', 'base_price' => 120]);
        $form['variants'][0]['price'] = 150;   // Small: its own price
        $form['variants'][1]['price'] = '';    // Large: the base price
        $form['variants'][1]['active'] = 0;    // and no longer sold

        $this->actingAs($this->owner)->put(route('inventory.update', $item), $form)->assertSessionHas('success');

        $item->refresh();
        $this->assertSame(['Test Keychain Deluxe', 120.0], [$item->name, (float) $item->base_price]);
        $this->assertSame([150.0, 'active'], [(float) $small->fresh()->price, $small->fresh()->status]);
        $this->assertSame([null, 'inactive'], [$large->fresh()->price, $large->fresh()->status]);

        // Billing/POS charges the new price, and no longer offers the hidden size.
        $catalog = $this->actingAs($this->cashier)->get(route('pos.index'))->viewData('catalog')[$item->id];
        $this->assertSame([150.0], collect($catalog['variants'])->pluck('price')->values()->all());
    }

    public function test_sizes_can_be_added_and_removed_and_a_sold_one_is_hidden_instead(): void
    {
        [$item, $small, $large] = $this->item();
        $blank = $this->material('Test Keychain Blank');
        $this->sellOne($item, $small);

        $form = $this->form($item);
        $form['variants'][0]['remove'] = 1;    // sold before: hidden, kept for its sale
        $form['variants'][1]['remove'] = 1;    // never sold: removed
        $form['variants'][] = ['id' => '', 'variant_name' => 'Medium', 'sku' => 'TEST-KC-MEDIUM', 'price' => 90, 'active' => 1, 'remove' => 0,
            'materials' => [['material_id' => $blank->id, 'quantity_per_unit' => 1]]];

        $this->actingAs($this->owner)->put(route('inventory.update', $item), $form)
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'Hidden instead of removed') && str_contains($message, 'Small'));

        $this->assertSame('inactive', $small->fresh()->status);
        $this->assertNull($large->fresh());
        $medium = ProductVariant::where('sku', 'TEST-KC-MEDIUM')->firstOrFail();
        $this->assertSame([90.0, 'active'], [(float) $medium->price, $medium->status]);
        $this->assertSame([$blank->id => 1.0], $medium->materials->mapWithKeys(fn ($m) => [$m->id => (float) $m->pivot->quantity_per_unit])->all());
    }

    public function test_what_a_size_is_made_from_can_be_changed(): void
    {
        [$item, $small] = $this->item();
        $blank = $this->material('Test Keychain Blank');
        $ring = $this->material('Test Key Ring');

        $form = $this->form($item);
        $form['variants'][0]['materials'] = [
            ['material_id' => $blank->id, 'quantity_per_unit' => 1],
            ['material_id' => $ring->id, 'quantity_per_unit' => 1],
            ['material_id' => $ring->id, 'quantity_per_unit' => 1],   // listed twice: it adds up
            ['material_id' => '', 'quantity_per_unit' => ''],         // an empty row is ignored
        ];
        $this->actingAs($this->owner)->put(route('inventory.update', $item), $form)->assertSessionHas('success');

        $this->assertEquals([$blank->id => 1.0, $ring->id => 2.0],
            $small->fresh()->materials->mapWithKeys(fn ($m) => [$m->id => (float) $m->pivot->quantity_per_unit])->all());
    }

    public function test_a_size_cut_from_a_board_keeps_its_recipe(): void
    {
        $sintra = Product::where('name', 'Sintra Board 5mm')->firstOrFail();
        $size = $sintra->variants()->where('variant_name', '8 x 11 in')->firstOrFail();
        $board = $size->materials()->first();

        $form = $this->form($sintra);
        $i = collect($form['variants'])->search(fn ($r) => $r['id'] === $size->id);
        $form['variants'][$i]['price'] = 220;
        $form['variants'][$i]['materials'] = [['material_id' => $this->material('Test Ink')->id, 'quantity_per_unit' => 1]];

        $this->actingAs($this->owner)->get(route('inventory.edit', $sintra))->assertOk()->assertSee('Cut from Sintra Board 5mm');
        $this->put(route('inventory.update', $sintra), $form)->assertSessionHas('success');

        $this->assertSame(220.0, (float) $size->fresh()->price);
        $this->assertSame([[$board->id, 'per_area']], $size->fresh()->materials->map(fn ($m) => [$m->id, $m->pivot->consumption_type])->all());
    }

    public function test_a_sku_another_size_uses_is_refused_and_nothing_is_saved(): void
    {
        [$item, $small] = $this->item();
        [, $other] = $this->item('Test Lanyard', 'TEST-LY');

        $form = $this->form($item, ['name' => 'Renamed']);
        $form['variants'][0]['sku'] = $other->sku;

        $this->actingAs($this->owner)->put(route('inventory.update', $item), $form)->assertSessionHasErrors('variants.0.sku');
        $this->assertSame('Test Keychain', $item->fresh()->name);
        $this->assertNotSame($other->sku, $small->fresh()->sku);
    }

    public function test_switching_to_a_stock_count_starts_from_zero_but_not_while_items_are_on_order(): void
    {
        [$item, $small] = $this->item();
        $blank = $this->material('Test Keychain Blank');   // none in stock
        $small->materials()->attach($blank->id, ['quantity_per_unit' => 1, 'consumption_type' => 'fixed']);
        $small->update(['stock_quantity' => 3]);           // a count it shouldn't have (an old void added it)

        $sale = $this->sellOne($item, $small);             // on order: the blank is out
        $this->assertTrue($sale->items->first()->isOnOrder());

        $this->actingAs($this->owner)->put(route('inventory.update', $item), $this->form($item, ['track_inventory' => 1]))
            ->assertSessionHasErrors('track_inventory');
        $this->assertFalse((bool) $item->fresh()->track_inventory);

        $this->post(route('sales.void', $sale));
        $this->put(route('inventory.update', $item), $this->form($item, ['track_inventory' => 1]))->assertSessionHas('success');
        $this->assertTrue((bool) $item->fresh()->track_inventory);
        $this->assertSame(0, $small->fresh()->stock_quantity);
    }

    // ------------------------------------------------------------------

    private function user(string $role): User
    {
        return User::create(['name' => ucfirst($role), 'email' => uniqid($role) . '@example.com', 'password' => bcrypt('secret-password'), 'role' => $role]);
    }

    // A made-to-order item with two sizes and no prices: [item, Small, Large].
    private function item(string $name = 'Test Keychain', string $sku = 'TEST-KC'): array
    {
        $item = Product::create(['name' => $name, 'sku' => $sku, 'base_price' => 0, 'has_variants' => true, 'track_inventory' => false, 'status' => 'active']);
        $small = $item->variants()->create(['variant_name' => 'Small', 'sku' => "{$sku}-S", 'stock_quantity' => 0, 'status' => 'active']);
        $large = $item->variants()->create(['variant_name' => 'Large', 'sku' => "{$sku}-L", 'stock_quantity' => 0, 'status' => 'active']);

        return [$item, $small, $large];
    }

    private function material(string $name): Material
    {
        return Material::create(['name' => $name . ' ' . uniqid(), 'unit' => 'pcs', 'inventory_type' => 'discrete', 'stock_quantity' => 0, 'low_stock_threshold' => 0]);
    }

    // The edit form as the page would send it, unchanged, with $changes on top.
    private function form(Product $item, array $changes = []): array
    {
        $item->load(['variants' => fn ($q) => $q->inShopOrder(), 'variants.materials']);

        return $changes + [
            'name' => $item->name,
            'sku' => $item->sku,
            'category_id' => $item->category_id,
            'description' => $item->description,
            'base_price' => (float) $item->base_price,
            'track_inventory' => $item->track_inventory ? 1 : 0,
            'variants' => $item->variants->map(fn ($v) => [
                'id' => $v->id, 'variant_name' => $v->variant_name, 'sku' => $v->sku,
                'price' => $v->price === null ? '' : (float) $v->price, 'low_stock_threshold' => $v->low_stock_threshold,
                'active' => $v->status === 'active' ? 1 : 0, 'remove' => 0,
                'materials' => $v->materials->filter(fn ($m) => ! $m->isSheet())->map(fn ($m) => ['material_id' => $m->id, 'quantity_per_unit' => (float) $m->pivot->quantity_per_unit])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function sellOne(Product $item, ProductVariant $size): Sale
    {
        $this->actingAs($this->cashier)->post(route('pos.store'), [
            'items' => [['product_id' => $item->id, 'product_variant_id' => $size->id, 'quantity' => 1, 'unit_price' => 80]],
            'payment_method' => 'cash', 'amount_paid' => 80,
        ])->assertSessionHas('success');

        return Sale::with('items')->latest('id')->firstOrFail();
    }
}
