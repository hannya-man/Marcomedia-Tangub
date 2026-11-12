<?php
namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\UsesTwoFactor;
use Tests\TestCase;

/**
 * Shifts end with a blind cash count against what the drawer should hold, and everyone's activity is
 * logged in the app's own words, with what an owner should check under "Needs a look".
 */
class ShiftsAndActivityTest extends TestCase
{
    use RefreshDatabase, UsesTwoFactor;

    private User $owner;
    private User $cashier;
    private Product $item;
    private ProductVariant $size;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->withTwoFactor(User::create(['name' => 'Olive Owner', 'email' => 'owner@example.com', 'password' => Hash::make('owner-password'), 'role' => 'admin']));
        $this->cashier = User::create(['name' => 'Cora Cashier', 'email' => 'cashier@example.com', 'password' => Hash::make('cashier-password'), 'role' => 'cashier']);
        $this->item = Product::create(['name' => 'Test Mug Print', 'sku' => uniqid('P'), 'base_price' => 450, 'has_variants' => true, 'track_inventory' => false, 'status' => 'active']);
        $this->size = $this->item->variants()->create(['variant_name' => 'Standard', 'sku' => uniqid('V'), 'stock_quantity' => 0, 'status' => 'active']);
    }

    public function test_the_drawer_should_hold_the_starting_cash_and_the_cash_taken_in_the_shift(): void
    {
        $this->actingAs($this->cashier)->from(route('pos.index'))->post(route('shifts.open'), ['opening_cash' => 500])
            ->assertSessionHas('success', 'Shift started, with ₱500.00 in the drawer.');
        $shift = Shift::openFor($this->cashier->id);
        $this->get(route('pos.index'))->assertSee('On shift since')->assertSee('End shift');

        $this->sell(500, 'cash');    // ₱450, ₱50 change: ₱450 goes in the drawer
        $this->sell(450, 'gcash');   // never in the drawer
        $owed = $this->sell(100, 'cash', ['new_customer_name' => 'Ana']);
        // Paid by the owner, who isn't on this shift: not in this drawer either.
        $this->actingAs($this->owner)->post(route('sales.payments.store', $owed), ['amount' => 350, 'method' => 'cash']);
        $this->assertEqualsWithDelta(1050, $shift->expectedCash(), 0.001);

        // Counted ₱50 short, hours later: the shift still ends, the difference is kept (as a warning, not
        // a success), and it goes under Needs a look. When it started stays as it was.
        $startedAt = $shift->opened_at->toDateTimeString();
        $this->travel(8)->hours();
        $this->actingAs($this->cashier)->from(route('pos.index'))->post(route('shifts.close', $shift), ['actual_cash' => 1000])
            ->assertRedirect(route('pos.index'))
            ->assertSessionMissing('success')
            ->assertSessionHas('warning', "Cora Cashier's shift ended. The drawer should have ₱1,050.00, and ₱1,000.00 was counted: ₱50.00 short. The owner will see it.");
        $shift->refresh();
        $this->assertSame(['closed', 1050.0, 1000.0, -50.0], [$shift->status, (float) $shift->expected_sales, (float) $shift->actual_cash, (float) $shift->variance]);
        $this->assertSame($startedAt, $shift->opened_at->toDateTimeString());
        $this->assertTrue(ActivityLog::where('action', 'shifts.close')->where('shift_id', $shift->id)->value('needs_review'));

        // The owner sees it: the list, and the shift's own timeline.
        $this->actingAs($this->owner)->get(route('shifts.index'))->assertOk()->assertSeeInOrder(['Cora Cashier', '₱1,350.00', '3 sales', '−₱50.00']);
        $this->get(route('shifts.show', $shift))->assertOk()
            ->assertSeeInOrder(['₱1,050.00', '₱50.00 short', 'Timeline', 'Shift started', 'Sale ', 'recorded as Processing', "Cora Cashier's shift ended"]);
        $this->actingAs($this->cashier)->get(route('shifts.index'))->assertRedirect(route('pos.index'));
    }

    public function test_a_count_that_matches_isnt_flagged(): void
    {
        $this->actingAs($this->cashier)->post(route('shifts.open'));
        $shift = Shift::openFor($this->cashier->id);
        $this->sell(450, 'cash');
        $this->post(route('shifts.close', $shift), ['actual_cash' => 450])->assertSessionHas('success', fn ($m) => str_ends_with($m, 'it matches.'));
        $this->assertFalse(ActivityLog::where('action', 'shifts.close')->value('needs_review'));
    }

    public function test_actions_are_logged_in_their_own_words_and_opening_pages_is_not(): void
    {
        $this->actingAs($this->cashier);
        $sale = $this->sell(450, 'cash');
        $this->get(route('pos.index'))->assertOk();
        $this->get(route('sales.index'))->assertOk();

        $log = ActivityLog::sole();
        $this->assertSame([$this->cashier->id, 'pos.store', "Sale {$sale->invoice_number} recorded as Processing.", false],
            [$log->user_id, $log->action, $log->description, $log->needs_review]);

        // A void is for the owner to look at.
        $this->actingAs($this->owner)->post(route('sales.void', $sale));
        $this->assertTrue(ActivityLog::where('action', 'sales.void')->sole()->needs_review);
    }

    public function test_logging_in_and_out_and_wrong_passwords_are_logged(): void
    {
        $this->post(route('login'), ['email' => 'cashier@example.com', 'password' => 'not-it']);
        $this->post(route('login'), ['email' => 'nobody@example.com', 'password' => 'not-it']);
        $this->post(route('login'), ['email' => 'cashier@example.com', 'password' => 'cashier-password']);
        $this->assertAuthenticatedAs($this->cashier);
        $this->post(route('logout'));

        $this->assertSame([
            [$this->cashier->id, 'login.failed', 'Wrong password at login', true],
            [null, 'login.failed', 'Login tried with an email no account has: n***@example.com', true],
            [$this->cashier->id, 'login', 'Logged in', false],
            [$this->cashier->id, 'logout', 'Logged out', false],
        ], ActivityLog::orderBy('id')->get()->map(fn ($l) => [$l->user_id, $l->action, $l->description, $l->needs_review])->all());
    }

    public function test_only_owners_see_the_activity_log_and_its_download_keeps_formulas_as_text(): void
    {
        ActivityLog::record($this->cashier->id, 'customers.update', '=HYPERLINK("http://example.com")');
        ActivityLog::record($this->cashier->id, 'login.failed', 'Wrong password at login', true);

        $this->actingAs($this->cashier)->get(route('activity.index'))->assertRedirect(route('pos.index'));
        $this->actingAs($this->owner)->get(route('activity.index'))->assertOk()->assertSee('Cora Cashier')->assertSee('=HYPERLINK', false);
        $this->get(route('activity.index', ['tab' => 'review']))->assertOk()->assertSee('Wrong password at login')->assertDontSee('HYPERLINK');

        $csv = $this->get(route('activity.download'))->assertOk()->streamedContent();
        $this->assertStringContainsString('"\'=HYPERLINK(""http://example.com"")"', $csv);
        $this->assertStringContainsString('"Cora Cashier","Wrong password at login",Yes', $csv);
    }

    private function sell(float $paid, string $method, array $extra = []): Sale
    {
        $this->post(route('pos.store'), $extra + [
            'items' => [['product_id' => $this->item->id, 'product_variant_id' => $this->size->id, 'quantity' => 1, 'unit_price' => 450]],
            'payment_method' => $method, 'amount_paid' => $paid,
        ])->assertSessionHas('success');

        return Sale::latest('id')->firstOrFail();
    }
}
