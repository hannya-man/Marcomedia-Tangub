<?php
namespace Tests\Feature;

use App\Models\ApprovalRequest;
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
 * What a cashier needs an owner for: deleting a product (never), adding or changing one, and
 * voiding a sale (an owner approves first). Needs MySQL or MariaDB.
 */
class ApprovalsTest extends TestCase
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

    public function test_a_cashier_cant_delete_or_bring_back_a_product(): void
    {
        $item = $this->product();

        $this->actingAs($this->cashier)->get(route('inventory.index', ['search' => $item->name]))->assertOk()
            ->assertDontSee('aria-label="Delete ' . $item->name . '"', false);
        $this->delete(route('inventory.destroy', $item))->assertRedirect(route('pos.index'));
        $this->assertSame('active', $item->fresh()->status);

        $this->actingAs($this->owner)->get(route('inventory.index', ['search' => $item->name]))
            ->assertSee('aria-label="Delete ' . $item->name . '"', false);
        $this->delete(route('inventory.destroy', $item))->assertSessionHas('success');
        $this->assertSame('archived', $item->fresh()->status);

        $this->actingAs($this->cashier)->post(route('inventory.restore', $item))->assertRedirect(route('pos.index'));
        $this->assertSame('archived', $item->fresh()->status);
        $this->get(route('inventory.archive'))->assertOk()->assertSee($item->name)->assertDontSee(route('inventory.restore', $item), false);
        $this->actingAs($this->owner)->get(route('inventory.archive'))->assertSee(route('inventory.restore', $item), false);
    }

    public function test_a_cashiers_new_product_waits_and_the_owner_approving_adds_it(): void
    {
        $blank = $this->material(0);

        $this->actingAs($this->cashier)->post(route('inventory.store'), $this->newProduct($blank))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'sent to the owner for approval'));
        $this->assertFalse(Product::where('sku', 'TEST-TUMB')->exists());
        $request = ApprovalRequest::sole();
        $this->assertSame(['product.create', 'pending', $this->cashier->id], [$request->type, $request->status, $request->requested_by]);

        // The owner sees it, in plain words, and approves it.
        $this->actingAs($this->owner)->get(route('approvals.index'))->assertOk()
            ->assertSee("Add product 'Test Tumbler'")->assertSee('TEST-TUMB-20')->assertSee($blank->name);
        $this->post(route('approvals.approve', $request))->assertSessionHas('success');

        $product = Product::where('sku', 'TEST-TUMB')->firstOrFail();
        $this->assertTrue((bool) $product->track_inventory);
        $this->assertSame(['20oz'], $product->variants->pluck('variant_name')->all());
        $this->assertSame([$blank->id], $product->variants->first()->materials->pluck('id')->all());
        $this->assertSame(['approved', $this->owner->id, $product->id], [$request->fresh()->status, $request->fresh()->decided_by, $request->fresh()->product_id]);

        // The cashier sees the answer, and seeing it clears the count in the menu.
        $this->actingAs($this->cashier)->get(route('pos.index'))->assertSee('New answers to your requests');
        $this->get(route('approvals.index'))->assertOk()->assertSee('Approved');
        $this->get(route('pos.index'))->assertDontSee('New answers to your requests');
    }

    public function test_approving_checks_the_request_again_and_a_rejection_tells_the_cashier_why(): void
    {
        $blank = $this->material(0);
        $this->actingAs($this->cashier)->post(route('inventory.store'), $this->newProduct($blank));
        $request = ApprovalRequest::sole();

        // Meanwhile the SKU was taken: approving it as sent is refused, and it keeps waiting.
        $this->product('TEST-TUMB');
        $this->actingAs($this->owner)->post(route('approvals.approve', $request))
            ->assertSessionHas('error', fn ($m) => str_contains($m, "can't be approved as it was sent"));
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(1, Product::where('sku', 'TEST-TUMB')->count());

        $this->post(route('approvals.reject', $request), ['note' => 'That SKU is taken. Use TEST-TUMB-2.'])->assertSessionHas('success');
        $this->assertSame(['rejected', 'That SKU is taken. Use TEST-TUMB-2.'], [$request->fresh()->status, $request->fresh()->decision_note]);
        $this->actingAs($this->cashier)->get(route('approvals.index'))->assertSee('Rejected')->assertSee('That SKU is taken. Use TEST-TUMB-2.');
    }

    public function test_a_cashiers_changes_wait_and_approving_saves_them(): void
    {
        $item = $this->product();
        $this->actingAs($this->cashier)->put(route('inventory.update', $item), $this->editForm($item, ['base_price' => 120]))->assertSessionHas('success');
        // Sending again replaces the earlier changes still waiting.
        $this->put(route('inventory.update', $item), $this->editForm($item, ['base_price' => 130]))->assertSessionHas('success');
        $this->assertSame(100.0, (float) $item->fresh()->base_price);
        $request = ApprovalRequest::sole();

        $this->actingAs($this->owner)->get(route('approvals.index'))->assertSee('Base price: ₱100.00 → ₱130.00', false);
        $this->post(route('approvals.approve', $request))->assertSessionHas('success');
        $this->assertSame(130.0, (float) $item->fresh()->base_price);
        $this->assertSame('approved', $request->fresh()->status);

        // Nothing changed: nothing is sent.
        $this->actingAs($this->cashier)->put(route('inventory.update', $item), $this->editForm($item->fresh()))->assertSessionHas('error');
        $this->assertSame(1, ApprovalRequest::count());
    }

    public function test_a_cashier_asks_to_void_and_the_owner_approving_voids_it(): void
    {
        [$sale, $blank] = $this->sale();

        $this->actingAs($this->cashier)->post(route('sales.void', $sale))->assertSessionHasErrors('reason');
        $this->post(route('sales.void', $sale), ['reason' => 'Customer cancelled.'])->assertSessionHas('success');
        $this->post(route('sales.void', $sale), ['reason' => 'Again.'])->assertSessionHas('error');
        $this->assertSame('processing', $sale->fresh()->status);
        $this->assertQty(0, $blank->fresh()->stock_quantity);
        $this->get(route('sales.index'))->assertSee('Void requested');

        $request = ApprovalRequest::sole();
        $this->actingAs($this->owner)->get(route('approvals.index'))->assertSee('Customer cancelled.')->assertSee($sale->invoice_number);
        $this->post(route('approvals.approve', $request))->assertSessionHas('success');
        $this->assertSame('voided', $sale->fresh()->status);
        $this->assertQty(1, $blank->fresh()->stock_quantity);
        $this->assertSame('approved', $request->fresh()->status);
    }

    public function test_a_void_request_for_a_sale_no_longer_open_cant_be_approved(): void
    {
        [$sale] = $this->sale();
        $this->actingAs($this->cashier)->post(route('sales.void', $sale), ['reason' => 'Wrong item.']);
        $request = ApprovalRequest::sole();
        $sale->update(['status' => 'completed']);

        $this->actingAs($this->owner)->get(route('approvals.index'))->assertSee('can no longer be voided');
        $this->post(route('approvals.approve', $request))->assertSessionHas('error');
        $this->assertSame(['completed', 'pending'], [$sale->fresh()->status, $request->fresh()->status]);
    }

    public function test_an_owner_voiding_the_sale_answers_the_cashiers_request(): void
    {
        [$sale] = $this->sale();
        $this->actingAs($this->cashier)->post(route('sales.void', $sale), ['reason' => 'Wrong item.']);

        $this->actingAs($this->owner)->post(route('sales.void', $sale))->assertSessionHas('success');
        $this->assertSame('voided', $sale->fresh()->status);
        $this->assertSame(['approved', $this->owner->id], [ApprovalRequest::sole()->status, ApprovalRequest::sole()->decided_by]);
    }

    public function test_a_cashier_cancels_only_their_own_request_and_only_owners_answer(): void
    {
        $item = $this->product();
        $this->actingAs($this->cashier)->put(route('inventory.update', $item), $this->editForm($item, ['base_price' => 120]));
        $request = ApprovalRequest::sole();

        $this->actingAs($this->cashier)->post(route('approvals.approve', $request))->assertRedirect(route('pos.index'));
        $this->post(route('approvals.reject', $request))->assertRedirect(route('pos.index'));

        $other = $this->user('cashier');
        $this->actingAs($other)->post(route('approvals.cancel', $request))->assertSessionHas('error');
        $this->get(route('approvals.index'))->assertDontSee("Change '{$item->name}'");
        $this->assertSame('pending', $request->fresh()->status);

        $this->actingAs($this->cashier)->post(route('approvals.cancel', $request))->assertSessionHas('success');
        $this->assertSame('cancelled', $request->fresh()->status);
        $this->actingAs($this->owner)->post(route('approvals.approve', $request))->assertSessionHas('error');
        $this->assertSame(100.0, (float) $item->fresh()->base_price);
    }

    // ------------------------------------------------------------------

    private function user(string $role): User
    {
        $user = User::create(['name' => ucfirst($role) . ' ' . uniqid(), 'email' => uniqid($role) . '@example.com', 'password' => bcrypt('secret-password'), 'role' => $role]);

        return $role === 'admin' ? $this->withTwoFactor($user) : $user;
    }

    private function product(?string $sku = null): Product
    {
        $item = Product::create(['name' => 'Test Keychain ' . uniqid(), 'sku' => $sku ?? uniqid('P'), 'base_price' => 100, 'has_variants' => true, 'track_inventory' => false, 'status' => 'active']);
        $item->variants()->create(['variant_name' => 'Standard', 'sku' => uniqid('V'), 'stock_quantity' => 0, 'low_stock_threshold' => 5, 'status' => 'active']);

        return $item;
    }

    private function material(float $inStore): Material
    {
        $blank = Material::create(['name' => uniqid('Test Blank '), 'unit' => 'pcs', 'inventory_type' => 'discrete', 'stock_quantity' => 0, 'low_stock_threshold' => 0]);
        if ($inStore > 0) {
            app(BatchInventoryService::class)->recordOpeningStock($blank, $inStore, 'store', $this->owner->id);
        }

        return $blank;
    }

    private function newProduct(Material $blank): array
    {
        return [
            'name' => 'Test Tumbler', 'sku' => 'TEST-TUMB', 'base_price' => 250, 'has_variants' => '1', 'track_inventory' => '1', 'uses_materials' => '1',
            'variants' => [[
                'variant_name' => '20oz', 'sku' => 'TEST-TUMB-20', 'price' => 250, 'low_stock_threshold' => 3,
                'materials' => [['material_id' => $blank->id, 'quantity_per_unit' => 1]],
            ]],
        ];
    }

    private function editForm(Product $item, array $changes = []): array
    {
        return $changes + [
            'name' => $item->name, 'sku' => $item->sku, 'category_id' => $item->category_id, 'description' => $item->description,
            'base_price' => (float) $item->base_price, 'track_inventory' => $item->track_inventory ? 1 : 0,
            'variants' => $item->variants()->get()->map(fn (ProductVariant $v) => [
                'id' => $v->id, 'variant_name' => $v->variant_name, 'sku' => $v->sku, 'price' => $v->price,
                'low_stock_threshold' => $v->low_stock_threshold, 'active' => 1, 'remove' => 0, 'materials' => [],
            ])->all(),
        ];
    }

    // A made-to-order mug taking one blank (the store's only one), rung up and paid by the cashier.
    private function sale(): array
    {
        $blank = $this->material(1);
        $mug = Product::create(['name' => 'Test Mug', 'sku' => uniqid('P'), 'base_price' => 150, 'has_variants' => true, 'track_inventory' => false, 'status' => 'active']);
        $type = $mug->variants()->create(['variant_name' => 'Standard', 'sku' => uniqid('V'), 'stock_quantity' => 0, 'status' => 'active']);
        $type->materials()->attach($blank->id, ['quantity_per_unit' => 1, 'consumption_type' => 'fixed']);

        $this->actingAs($this->cashier)->post(route('pos.store'), [
            'items' => [['product_id' => $mug->id, 'product_variant_id' => $type->id, 'quantity' => 1, 'unit_price' => 150]],
            'payment_method' => 'cash', 'amount_paid' => 150,
        ])->assertSessionHas('success');

        return [Sale::latest('id')->firstOrFail(), $blank];
    }

    private function assertQty(float $expected, $actual): void
    {
        $this->assertEqualsWithDelta($expected, (float) $actual, 0.0005);
    }
}
