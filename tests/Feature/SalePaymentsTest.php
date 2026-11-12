<?php
namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\UsesTwoFactor;
use Tests\TestCase;

/**
 * A sale's own payment history: the payment at the counter and each one after it, with how it was
 * paid, the reference, who took it and on which shift; and the customer balances (utang) built on it.
 */
class SalePaymentsTest extends TestCase
{
    use RefreshDatabase, UsesTwoFactor;

    private User $owner;
    private User $cashier;
    private Product $item;
    private ProductVariant $size;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->withTwoFactor(User::create(['name' => 'Olive Owner', 'email' => 'owner@example.com', 'password' => 'x', 'role' => 'admin']));
        $this->cashier = User::create(['name' => 'Cora Cashier', 'email' => 'cashier@example.com', 'password' => 'x', 'role' => 'cashier']);
        // Made to order, from nothing counted: a sale takes no stock.
        $this->item = Product::create(['name' => 'Test Tarp Print', 'sku' => uniqid('P'), 'base_price' => 450, 'has_variants' => true, 'track_inventory' => false, 'status' => 'active']);
        $this->size = $this->item->variants()->create(['variant_name' => '3x4 ft', 'sku' => uniqid('V'), 'stock_quantity' => 0, 'status' => 'active']);
    }

    public function test_each_payment_on_a_sale_is_kept_with_how_who_and_which_shift(): void
    {
        $this->actingAs($this->cashier)->post(route('shifts.open'), ['opening_cash' => 200]);
        $shift = Shift::openFor($this->cashier->id);

        // A down payment at the counter: the first line of the history.
        $sale = $this->ring($this->cashier, 200, ['new_customer_name' => 'Ana Reyes', 'new_customer_phone' => '0917']);
        $this->assertSame(['pending', $shift->id], [$sale->status, $sale->shift_id]);

        // Days later the owner (not on a shift) takes GCash, then the cashier takes cash, with change.
        $this->actingAs($this->owner)->post(route('sales.payments.store', $sale), ['amount' => 150, 'method' => 'gcash', 'reference' => '9876 543'])
            ->assertSessionHas('success', "₱150.00 GCash payment recorded for {$sale->invoice_number} (Ana Reyes). ₱100.00 still owed.");
        $this->assertSame('pending', $sale->fresh()->status);
        $this->actingAs($this->cashier)->post(route('sales.payments.store', $sale), ['amount' => 500, 'method' => 'cash'])
            ->assertSessionHas('success', "₱100.00 Cash payment recorded for {$sale->invoice_number} (Ana Reyes). Paid in full. Change: ₱400.00.");

        $this->assertSame([
            [200.0, 'cash', null, null, $this->cashier->id, $shift->id],
            [150.0, 'gcash', '9876 543', null, $this->owner->id, null],
            [100.0, 'cash', null, 'Cash received ₱500.00, change ₱400.00', $this->cashier->id, $shift->id],
        ], $sale->payments()->get()->map(fn (SalePayment $p) => [(float) $p->amount, $p->method, $p->reference, $p->note, $p->received_by, $p->shift_id])->all());
        // Paid in full: on to processing. What went to the sale adds up to its total.
        $sale->refresh();
        $this->assertSame(['processing', 850.0, 400.0], [$sale->status, (float) $sale->amount_paid, (float) $sale->change_amount]);
        $this->assertEqualsWithDelta(450, $sale->payments()->sum('amount'), 0.001);

        // The sale's page shows its own history, oldest first.
        $this->get(route('pos.receipt', $sale))->assertOk()
            ->assertSeeInOrder(['Payments', 'Cash', 'Received by Cora Cashier', '₱200.00', 'GCash', 'Ref. 9876 543', 'Received by Olive Owner', '₱150.00',
                'Received by Cora Cashier', 'change ₱400.00', '₱100.00', 'Paid so far', '₱450.00'])
            ->assertDontSee('Record a payment');
    }

    public function test_the_counter_needs_a_customer_for_a_down_payment_and_gcash_cant_give_change(): void
    {
        $this->actingAs($this->cashier);
        $this->from(route('pos.index'))->post(route('pos.store'), $this->cart(200))
            ->assertSessionHas('error', 'A down payment needs the customer: pick them, or add them as a new customer, so the balance shows under their name.');
        $this->from(route('pos.index'))->post(route('pos.store'), $this->cart(500, ['payment_method' => 'gcash']))
            ->assertSessionHas('error', "A GCash payment can't be more than the ₱450.00 owed. Enter ₱450.00, and send the rest back.");
        $this->assertSame(0, Sale::count());

        // Paid in full by GCash, with its reference number.
        $sale = $this->ring($this->cashier, 450, ['payment_method' => 'gcash', 'payment_reference' => '5551234']);
        $this->assertSame(['processing', 'gcash', '5551234'], [$sale->status, $sale->payments->first()->method, $sale->payments->first()->reference]);
    }

    public function test_payments_only_go_on_an_open_sale_with_something_owed(): void
    {
        $sale = $this->ring($this->cashier, 450);
        $this->post(route('sales.payments.store', $sale), ['amount' => 10, 'method' => 'cash'])->assertSessionHas('error', "{$sale->invoice_number} is already paid in full.");

        $owing = $this->ring($this->cashier, 100, ['new_customer_name' => 'Ben']);
        $this->post(route('sales.payments.store', $owing), ['amount' => 0, 'method' => 'cash'])->assertSessionHasErrors('amount');
        $this->post(route('sales.payments.store', $owing), ['amount' => 351, 'method' => 'gcash'])->assertSessionHas('error');
        $this->actingAs($this->owner)->post(route('sales.void', $owing));
        $this->post(route('sales.payments.store', $owing), ['amount' => 50, 'method' => 'cash'])
            ->assertSessionHas('error', "{$owing->invoice_number} is Voided. Payments are only taken on open sales.");
        $this->assertSame(1, $owing->payments()->count());
    }

    public function test_the_balances_page_lists_who_owes_and_a_customers_page_shows_every_purchase_and_payment(): void
    {
        $ana = $this->ring($this->cashier, 200, ['new_customer_name' => 'Ana Reyes']);
        $this->travel(1)->hours();
        $this->ring($this->cashier, 450, ['customer_id' => $ana->customer_id]);   // paid in full
        $this->travel(1)->hours();
        $this->ring($this->cashier, 450, ['new_customer_name' => 'Ben Cruz']);   // paid in full
        $this->travel(1)->hours();
        $this->post(route('sales.payments.store', $ana), ['amount' => 50, 'method' => 'cash']);

        // Who owes, most first. Ben paid in full, so he's listed only with everyone.
        $this->get(route('customers.index'))->assertOk()
            ->assertSeeInOrder(['Owed to you', '₱200.00', 'Customers who owe', '1', 'Ana Reyes', '₱200.00', 'on 1 sale'])
            ->assertDontSee('Ben Cruz');
        $this->get(route('customers.index', ['all' => 1]))->assertSee('Ben Cruz')->assertSee('Paid up');

        // Ana's page: what's still owed, sale by sale, and her whole history, newest first.
        $this->get(route('customers.show', $ana->customer_id))->assertOk()
            ->assertSeeInOrder(['Still owes', '₱200.00', 'Sales with a balance', $ana->invoice_number, 'Record payment', 'History'])
            ->assertSeeInOrder(['History', 'Paid Cash', $ana->invoice_number, '− ₱50.00', 'Paid Cash', '− ₱450.00', 'Bought', '+ ₱450.00',
                'Paid Cash', '− ₱200.00', 'Bought', $ana->invoice_number, '+ ₱450.00']);

        $this->patch(route('customers.update', $ana->customer_id), ['name' => 'Ana R. Reyes', 'phone' => '0917 000 1111'])->assertSessionHas('success');
        $this->assertSame('0917 000 1111', $ana->customer->fresh()->phone);
    }

    private function cart(float $paid, array $extra = []): array
    {
        return $extra + [
            'items' => [['product_id' => $this->item->id, 'product_variant_id' => $this->size->id, 'quantity' => 1, 'unit_price' => 450]],
            'payment_method' => 'cash', 'amount_paid' => $paid,
        ];
    }

    private function ring(User $who, float $paid, array $extra = []): Sale
    {
        $this->actingAs($who)->post(route('pos.store'), $this->cart($paid, $extra))->assertSessionHas('success');

        return Sale::with('payments')->latest('id')->firstOrFail();
    }
}
