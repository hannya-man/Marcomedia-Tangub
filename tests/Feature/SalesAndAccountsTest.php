<?php
namespace Tests\Feature;

use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\Inventory\BatchInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\UsesTwoFactor;
use Tests\TestCase;

/**
 * Loopholes closed: who can make accounts, what Edit and the archive can do to a sale, invoice
 * numbers, and a few forms that didn't do what they said. Needs MySQL or MariaDB.
 */
class SalesAndAccountsTest extends TestCase
{
    use RefreshDatabase, UsesTwoFactor;

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

    // (A forgotten password is set again by email, once email is set up: EmailRecoveryTest.)
    public function test_there_is_no_sign_up_page(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => 'password1', 'password_confirmation' => 'password1'])->assertNotFound();
        $this->assertFalse(User::where('email', 'x@example.com')->exists());
    }

    public function test_staff_cant_delete_their_own_account(): void
    {
        $this->actingAs($this->cashier)->delete('/profile', ['password' => 'secret-password'])->assertStatus(405);
        $this->assertNotNull($this->cashier->fresh());
    }

    public function test_the_last_owner_cant_be_made_a_cashier(): void
    {
        $this->actingAs($this->owner)->patch(route('users.update', $this->owner), $this->profile($this->owner, 'cashier'))
            ->assertSessionHas('error');
        $this->assertSame('admin', $this->owner->fresh()->role);

        // With a second owner, it's fine.
        $second = $this->user('admin');
        $this->patch(route('users.update', $this->owner), $this->profile($this->owner, 'cashier'))->assertSessionHas('success');
        $this->assertSame('cashier', $this->owner->fresh()->role);
        $this->assertSame('admin', $second->fresh()->role);
    }

    public function test_voiding_through_edit_gives_the_stock_back_and_a_void_is_final(): void
    {
        [$mug, $type, $blank] = $this->mug(1);
        $sale = $this->sell($mug, $type);
        $this->assertQty(0, $blank->fresh()->stock_quantity);

        // A cashier can't void through Edit: they ask an owner with the Void button.
        $this->patch(route('sales.update', $sale), ['status' => 'voided'])->assertSessionHas('error');
        $this->assertSame('processing', $sale->fresh()->status);

        $this->actingAs($this->owner)->patch(route('sales.update', $sale), ['status' => 'voided'])->assertSessionHas('success');
        $this->assertSame('voided', $sale->fresh()->status);
        $this->assertQty(1, $blank->fresh()->stock_quantity);

        // Un-voiding would leave the blank in stock while the sale counts again.
        $this->patch(route('sales.update', $sale), ['status' => 'processing'])->assertSessionHas('error');
        $this->assertSame('voided', $sale->fresh()->status);
    }

    public function test_a_sale_isnt_processing_or_completed_until_it_is_paid(): void
    {
        [$mug, $type] = $this->mug(1);
        $sale = $this->sell($mug, $type, 50);   // P50 down on P150
        $this->assertSame('pending', $sale->status);

        $this->patch(route('sales.update', $sale), ['status' => 'completed'])->assertSessionHas('error');
        $this->assertSame('pending', $sale->fresh()->status);

        // Paying the rest and completing in one go completes it (it used to stop at processing).
        $this->patch(route('sales.update', $sale), ['status' => 'completed', 'additional_payment' => 100])->assertSessionHas('success');
        $this->assertSame(['completed', 150.0], [$sale->fresh()->status, (float) $sale->fresh()->amount_paid]);
    }

    public function test_the_archive_is_the_owners_and_restore_keeps_what_the_sale_was(): void
    {
        [$mug, $type] = $this->mug(2);
        $voided = $this->sell($mug, $type);
        $this->actingAs($this->owner)->post(route('sales.void', $voided));
        $paid = $this->sell($mug, $type);   // the cashier again

        // A cashier can't make a paid sale leave the day's totals.
        $this->post(route('sales.archiveOne', $paid))->assertRedirect(route('pos.index'));
        $this->assertSame('processing', $paid->fresh()->status);
        $this->get(route('sales.index'))->assertDontSee(route('sales.archive'), false);

        $this->actingAs($this->owner);
        $this->post(route('sales.archiveOne', $voided))->assertSessionHas('success');
        $this->post(route('sales.restore', $voided))->assertSessionHas('success');
        $this->assertSame('voided', $voided->fresh()->status);   // not revenue
    }

    public function test_invoice_numbers_go_on_from_the_days_last_one(): void
    {
        [$mug, $type] = $this->mug(3);
        $first = $this->sell($mug, $type);
        // Numbers needn't be a count: one archived or skipped still leaves the next one free.
        $first->update(['invoice_number' => 'INV-' . now()->format('Ymd') . '-0041']);

        $this->assertSame('INV-' . now()->format('Ymd') . '-0042', $this->sell($mug, $type)->invoice_number);
    }

    public function test_a_product_added_without_a_stock_count_is_made_to_order(): void
    {
        $this->actingAs($this->owner)->post(route('inventory.store'), [
            'name' => 'Test Plaque', 'sku' => 'TEST-PLAQUE', 'base_price' => 300, 'has_variants' => '1',
            'variants' => [['variant_name' => 'Standard', 'sku' => 'TEST-PLAQUE-STD']],
        ])->assertRedirect(route('inventory.index'));
        $this->assertFalse((bool) Product::where('sku', 'TEST-PLAQUE')->value('track_inventory'));

        $this->post(route('inventory.store'), [
            'name' => 'Test Shirt', 'sku' => 'TEST-SHIRT', 'base_price' => 300, 'track_inventory' => '1',
        ]);
        $this->assertTrue((bool) Product::where('sku', 'TEST-SHIRT')->value('track_inventory'));
    }

    public function test_adding_an_archived_categorys_name_brings_it_back(): void
    {
        $category = Category::create(['name' => 'Test Banners', 'slug' => 'test-banners', 'archived_at' => now()]);

        $this->actingAs($this->cashier)->post(route('categories.store'), ['name' => 'Test Banners'])->assertSessionHas('success');
        $this->assertNull($category->fresh()->archived_at);

        $this->post(route('categories.store'), ['name' => 'Test Banners'])->assertSessionHas('error');
        $this->assertSame(1, Category::where('slug', 'test-banners')->count());
    }

    public function test_the_dashboard_doesnt_count_made_to_order_sizes_as_out_of_stock(): void
    {
        $before = $this->actingAs($this->owner)->get(route('dashboard'))->assertOk()->viewData('outOfStockCount');

        // Ten made-to-order sizes with no count of their own: not out of stock.
        $item = Product::create(['name' => 'Test Pin', 'sku' => uniqid('P'), 'base_price' => 0, 'has_variants' => true, 'track_inventory' => false, 'status' => 'active']);
        foreach (range(1, 10) as $i) {
            $item->variants()->create(['variant_name' => "Size {$i}", 'sku' => uniqid('V'), 'stock_quantity' => 0, 'status' => 'active']);
        }
        // A stocked one with none left is.
        $stocked = Product::create(['name' => 'Test Cap', 'sku' => uniqid('P'), 'base_price' => 100, 'has_variants' => true, 'track_inventory' => true, 'status' => 'active']);
        $stocked->variants()->create(['variant_name' => 'Free size', 'sku' => uniqid('V'), 'stock_quantity' => 0, 'status' => 'active']);

        $this->assertSame($before + 1, $this->get(route('dashboard'))->viewData('outOfStockCount'));
    }

    // ------------------------------------------------------------------

    private function user(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid($role) . '@example.com', 'password' => bcrypt('secret-password'), 'role' => $role]);

        return $role === 'admin' ? $this->withTwoFactor($user) : $user;
    }

    private function profile(User $user, string $role): array
    {
        return ['name' => $user->name, 'email' => $user->email, 'role' => $role];
    }

    // A made-to-order mug taking one blank each, with $blanks in the store: [item, type, blank].
    private function mug(float $blanks): array
    {
        $blank = Material::create(['name' => uniqid('Mug Blank '), 'unit' => 'pcs', 'inventory_type' => 'discrete', 'stock_quantity' => 0, 'low_stock_threshold' => 0]);
        app(BatchInventoryService::class)->recordOpeningStock($blank, $blanks, 'store', $this->owner->id);
        $mug = Product::create(['name' => 'Test Mug', 'sku' => uniqid('P'), 'base_price' => 150, 'has_variants' => true, 'track_inventory' => false, 'status' => 'active']);
        $type = $mug->variants()->create(['variant_name' => 'Standard', 'sku' => uniqid('V'), 'stock_quantity' => 0, 'status' => 'active']);
        $type->materials()->attach($blank->id, ['quantity_per_unit' => 1, 'consumption_type' => 'fixed']);

        return [$mug, $type, $blank];
    }

    // One mug rung up by the cashier at P150, paid $paid.
    private function sell(Product $mug, ProductVariant $type, float $paid = 150): Sale
    {
        $this->actingAs($this->cashier)->post(route('pos.store'), [
            'items' => [['product_id' => $mug->id, 'product_variant_id' => $type->id, 'quantity' => 1, 'unit_price' => 150]],
            'payment_method' => 'cash', 'amount_paid' => $paid,
        ])->assertSessionHas('success');

        return Sale::latest('id')->firstOrFail();
    }

    private function assertQty(float $expected, $actual): void
    {
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.0005);
    }
}
