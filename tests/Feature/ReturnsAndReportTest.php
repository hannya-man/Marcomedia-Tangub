<?php
namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\UsesTwoFactor;
use Tests\TestCase;

/**
 * Returns (owner only) put a good item back on sale and keep a damaged one apart, never more than was
 * sold. The sales report counts completed sales and every payment in its period. And a password the
 * owner sets follows the same rule as the emailed one, and ends the old logins.
 */
class ReturnsAndReportTest extends TestCase
{
    use RefreshDatabase, UsesTwoFactor;

    private User $owner;
    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->withTwoFactor(User::create(['name' => 'Olive Owner', 'email' => 'owner@example.com', 'password' => 'x', 'role' => 'admin']));
        $this->cashier = User::create(['name' => 'Cora Cashier', 'email' => 'cashier@example.com', 'password' => 'x', 'role' => 'cashier']);
    }

    public function test_a_good_return_goes_back_on_sale_and_a_damaged_one_is_kept_apart(): void
    {
        [$shirt, $size] = $this->stocked(5);
        $sale = $this->sell($shirt, $size, 3);
        $line = $sale->items()->sole();
        $this->assertSame(2, $size->fresh()->sellable_quantity);

        $this->actingAs($this->owner)->get(route('returns.index', ['invoice' => $sale->invoice_number]))->assertOk()
            ->assertSee('Test Shirt (Medium): up to 3');
        $this->post(route('returns.store'), ['sale_item_id' => $line->id, 'quantity' => 1, 'condition' => 'good', 'reason' => 'Wrong size'])
            ->assertSessionHas('success', "Return accepted: 1 x Test Shirt (Medium) from {$sale->invoice_number}, good. It's back in stock.");
        $this->assertSame([3, 0, 3], [$size->fresh()->stock_quantity, $size->fresh()->damaged_quantity, $size->fresh()->sellable_quantity]);

        // Damaged: on hand again, but never sold (it used to take a good one off the count instead).
        $this->post(route('returns.store'), ['sale_item_id' => $line->id, 'quantity' => 1, 'condition' => 'damaged', 'reason' => 'Torn'])->assertSessionHas('success');
        $this->assertSame([4, 1, 3], [$size->fresh()->stock_quantity, $size->fresh()->damaged_quantity, $size->fresh()->sellable_quantity]);

        $this->post(route('returns.store'), ['sale_item_id' => $line->id, 'quantity' => 2, 'condition' => 'good', 'reason' => 'Extra'])
            ->assertSessionHas('error', "Only 1 of Test Shirt on {$sale->invoice_number} can still be returned.");
        $this->post(route('returns.store'), ['sale_item_id' => $line->id, 'quantity' => 1, 'condition' => 'good'])->assertSessionHasErrors('reason');
        $this->get(route('returns.index'))->assertSeeInOrder(['Recent returns', 'Damaged', 'Torn', 'Good', 'Wrong size']);
    }

    public function test_nothing_comes_back_from_a_voided_sale_and_only_owners_take_returns(): void
    {
        [$shirt, $size] = $this->stocked(5);
        $sale = $this->sell($shirt, $size, 2);
        $this->actingAs($this->owner)->post(route('sales.void', $sale));
        $this->assertSame(5, $size->fresh()->stock_quantity);

        $this->post(route('returns.store'), ['sale_item_id' => $sale->items()->sole()->id, 'quantity' => 1, 'condition' => 'good', 'reason' => 'Wrong size'])
            ->assertSessionHas('error', "{$sale->invoice_number} is Voided: nothing on it can be returned.");
        $this->assertSame(5, $size->fresh()->stock_quantity);

        $this->actingAs($this->cashier)->get(route('returns.index'))->assertRedirect(route('pos.index'));
    }

    public function test_the_report_counts_completed_sales_and_every_payment_in_the_period(): void
    {
        [$shirt, $size] = $this->stocked(10);
        $done = $this->sell($shirt, $size, 2);          // ₱300, paid in full
        $this->actingAs($this->owner)->post(route('sales.complete', $done));
        $this->sell($shirt, $size, 1, 50, 'gcash');     // ₱150, ₱50 down: not completed

        $this->actingAs($this->owner)->get(route('reports.sales'))->assertOk()
            ->assertSeeInOrder(['Sales total', '₱300.00', 'Completed sales', '1', 'Money received', '₱350.00', 'Cash', '₱300.00', 'GCash', '₱50.00'])
            ->assertSeeInOrder(['Best sellers', 'Test Shirt', '2 sold', 'What each person sold', 'Cora Cashier', '₱300.00']);
        foreach (['week', 'month', 'year'] as $period) {
            $this->get(route('reports.sales', ['period' => $period]))->assertOk()->assertSee('₱300.00');
        }
        $this->get(route('reports.sales', ['period' => 'day', 'date' => now()->subDay()->toDateString()]))->assertOk()->assertSee('Nothing sold.');
        $this->get(route('reports.sales', ['period' => 'fortnight']))->assertSessionHasErrors('period');

        $csv = $this->get(route('reports.sales.download', ['period' => 'month']))->assertOk()->streamedContent();
        foreach (['"Completed sales",1', '"Sales total",300', 'Cash,1,300', 'GCash,1,50', '"Test Shirt",Medium', '"Cora Cashier",1,300'] as $row) {
            $this->assertStringContainsString($row, $csv);
        }
        $this->actingAs($this->cashier)->get(route('reports.sales'))->assertRedirect(route('pos.index'));
    }

    public function test_a_password_the_owner_sets_needs_8_characters_and_ends_the_old_logins(): void
    {
        $this->cashier->forceFill(['remember_token' => 'remembered-on-a-lost-phone'])->save();
        $profile = ['name' => $this->cashier->name, 'email' => $this->cashier->email, 'role' => 'cashier'];

        $this->actingAs($this->owner)->patch(route('users.update', $this->cashier), $profile + ['password' => 'short'])->assertSessionHasErrors('password');
        $this->patch(route('users.update', $this->cashier), $profile + ['password' => 'new-password'])
            ->assertSessionHas('success', "Cora Cashier's account updated. The new password works now, and any other device they were logged in on is logged out.");
        $this->cashier->refresh();
        $this->assertTrue(Hash::check('new-password', $this->cashier->password));
        $this->assertNotSame('remembered-on-a-lost-phone', $this->cashier->remember_token);
    }

    // A stocked item (its own count, no materials): [product, size].
    private function stocked(int $count): array
    {
        $shirt = Product::create(['name' => 'Test Shirt', 'sku' => uniqid('P'), 'base_price' => 150, 'has_variants' => true, 'track_inventory' => true, 'status' => 'active']);

        return [$shirt, $shirt->variants()->create(['variant_name' => 'Medium', 'sku' => uniqid('V'), 'stock_quantity' => $count, 'status' => 'active'])];
    }

    private function sell(Product $item, ProductVariant $size, int $quantity, ?float $paid = null, string $method = 'cash'): Sale
    {
        $this->actingAs($this->cashier)->post(route('pos.store'), [
            'items' => [['product_id' => $item->id, 'product_variant_id' => $size->id, 'quantity' => $quantity, 'unit_price' => 150]],
            'payment_method' => $method, 'amount_paid' => $paid ?? 150 * $quantity, 'new_customer_name' => 'Ana',
        ])->assertSessionHas('success');

        return Sale::latest('id')->firstOrFail();
    }
}
