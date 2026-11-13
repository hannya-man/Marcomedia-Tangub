<?php
namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\Services\Inventory\BatchInventoryService;
use App\Services\Sales\SalesReport;
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

        $csv = $this->get(route('reports.sales.download', ['format' => 'csv', 'period' => 'month']))->assertOk()->streamedContent();
        foreach (['"Completed sales",1', '"Sales total",300', 'Cash,1,300', 'GCash,1,50', '"Test Shirt",Medium', '"Cora Cashier",1,2,300,300,0'] as $row) {
            $this->assertStringContainsString($row, $csv);
        }
        $this->actingAs($this->cashier)->get(route('reports.sales'))->assertRedirect(route('pos.index'));
    }

    public function test_the_excel_file_is_a_dashboard_with_a_sheet_per_table_and_names_never_run_as_formulas(): void
    {
        [$shirt, $size] = $this->stocked(10);
        $sale = $this->sell($shirt, $size, 2);
        $sale->customer->update(['name' => '=HYPERLINK("http://example.com")']);
        $this->actingAs($this->owner)->post(route('sales.complete', $sale));

        $file = $this->get(route('reports.sales.download', ['period' => 'month']))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($file));
        $workbook = $zip->getFromName('xl/workbook.xml');
        $sheets = ['Dashboard', ...array_values(array_diff(SalesReport::TABLES, ['Summary']))];
        $xml = [];
        foreach ($sheets as $i => $title) {
            $this->assertStringContainsString('name="' . $title . '"', $workbook);
            $xml[$title] = $zip->getFromName('xl/worksheets/sheet' . ($i + 1) . '.xml');
            $this->assertNotFalse(simplexml_load_string($xml[$title]), "$title is good XML");
        }
        foreach ([1, 2, 3] as $n) {
            $this->assertNotFalse(simplexml_load_string($zip->getFromName("xl/charts/chart{$n}.xml")), "chart {$n}");
        }

        // The dashboard's totals are formulas over the sheets, with today's values until Excel works them out.
        $this->assertMatchesRegularExpression('#<c r="A6" s="\d+"><f>SUM\(Sales!\$H\$2:\$H\$2\)</f><v>300</v></c>#', $xml['Dashboard']);
        $this->assertStringContainsString('<f>SUM(&apos;Items sold&apos;!$J$2:$J$2)</f><v>2</v>', $xml['Dashboard']);
        // Each table: real numbers, and a Total row that follows the filter.
        $this->assertMatchesRegularExpression('#Cora Cashier</t></is></c><c r="B2" s="\d+"><v>1</v></c><c r="C2" s="\d+"><v>2</v></c><c r="D2" s="\d+"><v>300</v>#', $xml['By staff']);
        $this->assertStringContainsString('<f>SUBTOTAL(109,H2:H2)</f><v>300</v>', $xml['Sales']);
        // A name typed like a formula stays text.
        $this->assertStringContainsString('t="inlineStr"><is><t xml:space="preserve">=HYPERLINK(&quot;http://example.com&quot;)</t>', $xml['Sales']);
        $this->assertStringNotContainsString('<f>HYPERLINK("http://example.com")', $xml['Sales']);
        $zip->close();

        // The Items sold sheet reads back in, ready to import (nothing new: that sale is in already).
        $plan = $this->post(route('reports.import.check'), ['file' => new \Illuminate\Http\UploadedFile($file, 'report.xlsx', null, null, true)])->assertOk()->json();
        $this->assertSame([], $plan['sales']);
        $this->assertSame(["{$sale->invoice_number} is already in the system, so it was left out."], $plan['skipped']);
    }

    public function test_sales_import_from_a_sheet_dated_when_they_were_sold(): void
    {
        [$shirt, $size] = $this->stocked(10);
        $csv = tempnam(sys_get_temp_dir(), 'imp');
        $day = now()->subDays(3)->toDateString();
        file_put_contents($csv, implode("\n", [
            'Date,Time,Invoice,Staff,Customer,Item,Size or type,SKU,Quantity,Unit price,Paid by',
            "{$day},9:30 AM,OR-1,Cora Cashier,Ana,Test Shirt,Medium,,2,150,Cash",
            "{$day},9:30 AM,OR-1,Cora Cashier,Ana,,,{$size->sku},1,150,Cash",
            "{$day},2:00 PM,OR-2,Nobody Known,,Test Shirt,Medium,,1,160,GCash",
            "{$day},3:00 PM,OR-3,,,Mystery Item,,,1,50,Cash",
            "{$day},3:00 PM,OR-3,,,Test Shirt,Medium,,1,150,Cash",
            now()->addDay()->toDateString() . ',9:00 AM,OR-4,,,Test Shirt,Medium,,1,150,Cash',
        ]));
        $file = fn () => new \Illuminate\Http\UploadedFile($csv, 'paper sales.csv', 'text/csv', null, true);

        $this->actingAs($this->owner);
        $plan = $this->post(route('reports.import.check'), ['file' => $file()])->assertOk()->json();
        $this->assertSame(['OR-1', 'OR-2'], array_column($plan['sales'], 'invoice'));
        $this->assertSame(610, (int) $plan['total']);
        $this->assertSame([$this->cashier->id, $this->owner->id], array_column($plan['sales'], 'staff_id'));   // unknown staff: the importer
        $this->assertSame([
            'Row 5: No product called "Mystery Item". Check the name or SKU on the Products page.',
            'So OR-3 (rows 6) was left out too: a sale goes in whole.',
            'Row 7: ' . now()->addDay()->toDateString() . ' 09:00:00 hasn\'t happened yet.',
        ], $plan['skipped']);

        $result = $this->postJson(route('reports.import.run'), ['sales' => $plan['sales']])->assertOk()->json();
        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['imported']);

        $first = Sale::where('notes', 'like', '%(sheet invoice OR-1)%')->sole();
        $this->assertSame(['completed', 450.0, 450.0, null], [$first->status, (float) $first->total_amount, (float) $first->amount_paid, $first->shift_id]);
        $this->assertSame("{$day} 09:30", $first->created_at->format('Y-m-d H:i'));
        $this->assertSame("{$day} 09:30", $first->payments()->sole()->created_at->format('Y-m-d H:i'));
        $this->assertSame('INV-' . str_replace('-', '', $day) . '-0001', $first->invoice_number);
        $this->assertSame([$this->cashier->id, 'Ana'], [$first->user_id, $first->customer->name]);
        $this->assertSame(6, $size->fresh()->stock_quantity);   // 10 - 3 - 1
        $this->assertSame('gcash', Sale::where('notes', 'like', '%(sheet invoice OR-2)%')->sole()->payment_method);
        $this->assertTrue(ActivityLog::where('action', 'sales.import')->sole()->needs_review);

        // The same file again adds nothing.
        $again = $this->post(route('reports.import.check'), ['file' => $file()])->json();
        $this->assertSame([], $again['sales']);
        $this->postJson(route('reports.import.run'), ['sales' => $plan['sales']])->assertOk()->assertJsonCount(0, 'imported')->assertJsonCount(2, 'errors');
        $this->assertSame(2, Sale::count());

        // A file that isn't a sales sheet, and cashiers, are turned away.
        $this->post(route('reports.import.check'), ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('notes.txt', 'hello')])
            ->assertStatus(422)->assertJson(['message' => 'Import an Excel file (.xlsx) or a CSV file (.csv).']);
        $this->get(route('reports.import.template'))->assertOk()->assertDownload('sales-import-template.xlsx');
        $this->actingAs($this->cashier)->post(route('reports.import.check'), ['file' => $file()])->assertRedirect(route('pos.index'));
    }

    public function test_one_persons_report_and_the_stock_behind_the_sales(): void
    {
        [$shirt, $size] = $this->stocked(10);
        $sale = $this->sell($shirt, $size, 2);   // Cora: ₱300
        $this->actingAs($this->owner)->post(route('sales.complete', $sale));
        $this->actingAs($this->owner)->post(route('pos.store'), [
            'items' => [['product_id' => $shirt->id, 'product_variant_id' => $size->id, 'quantity' => 1, 'unit_price' => 150]],
            'payment_method' => 'cash', 'amount_paid' => 150,
        ]);
        $this->post(route('sales.complete', Sale::latest('id')->first()));   // Olive: ₱150

        // Ink at ₱2 a ml: 40 ml used, 15 ml spilled, 45 ml left (under its reorder point of 50).
        $ink = Material::create(['name' => 'Test Ink', 'unit' => 'ml', 'inventory_type' => 'continuous', 'stock_quantity' => 0, 'low_stock_threshold' => 50]);
        app(BatchInventoryService::class)->recordOpeningStock($ink, 100, 'store', $this->cashier->id, 2);
        $this->actingAs($this->cashier);
        if (! $ink->activeBatch()->exists()) {
            $this->post(route('stock.open-next', $ink));
        }
        $this->post(route('stock.pull', $ink), ['quantity' => 40])->assertSessionHas('success');
        $this->post(route('stock.loss', $ink), ['quantity' => 15, 'reason' => 'Spilled'])->assertSessionHas('success');

        $this->actingAs($this->owner)->get(route('reports.sales', ['period' => 'month']))->assertOk()
            ->assertSeeInOrder(['Sales total', '₱450.00', 'What each person sold', 'Cora Cashier', '2', '₱300.00', 'Olive Owner', '1', '₱150.00'])
            ->assertSeeInOrder(['Running low now', 'Test Ink', 'Continuous raw material', '45 ml', 'reorder at 50'])
            ->assertSeeInOrder(['Continuous raw materials used', '₱80.00', 'Test Ink', '40 ml', 'Waste and rejected runs', '₱30.00', 'Test Ink', 'Damaged', '15 ml']);
        $this->get(route('reports.sales', ['period' => 'month', 'user' => $this->cashier->id]))->assertOk()
            ->assertSeeInOrder(['Sales total', '₱300.00', 'Showing Cora Cashier only', 'Every sale by Cora Cashier'])
            ->assertDontSee('Olive Owner</td>', false);
        $csv = $this->get(route('reports.sales.download', ['format' => 'csv', 'period' => 'month', 'user' => $this->cashier->id]))
            ->assertDownload('sales-report-month-' . now()->startOfMonth()->toDateString() . '-cora-cashier.csv')->streamedContent();
        $this->assertStringContainsString('"Continuous raw material","Test Ink",40,ml,80', $csv);
        $this->assertStringNotContainsString('Olive Owner', $csv);
    }

    public function test_a_google_sheets_link_shows_one_table_until_it_is_turned_off(): void
    {
        [$shirt, $size] = $this->stocked(10);
        $sale = $this->sell($shirt, $size, 2);
        $this->actingAs($this->owner)->post(route('sales.complete', $sale));

        $this->post(route('reports.sheet-link'))->assertSessionHas('success')->assertSessionHas('sheet_link');
        $link = session('sheet_link');
        $this->assertSame(64, strlen($this->owner->fresh()->sheet_token));   // only its hash is kept
        $this->assertStringNotContainsString($this->owner->fresh()->sheet_token, $link);
        $this->assertTrue(ActivityLog::where('action', 'reports.sheet-link')->sole()->needs_review);

        auth()->logout();
        $csv = $this->get("{$link}?table=staff&period=month")->assertOk()->assertHeader('Cache-Control', 'no-store, private')->streamedContent();
        $this->assertStringStartsWith('Staff,Sales,"Items sold",Revenue', $csv);   // no Excel mark at the start
        $this->assertStringContainsString('"Cora Cashier",1,2,300', $csv);
        $this->get("{$link}?table=passwords")->assertNotFound();
        $this->get(route('reports.sheet', str_repeat('a', 40)))->assertNotFound();

        // A new link turns the old one off, and so does "Turn the link off".
        $this->actingAs($this->owner)->post(route('reports.sheet-link'));
        $newer = session('sheet_link');
        $this->get($link)->assertNotFound();
        $this->get($newer)->assertOk();
        $this->delete(route('reports.sheet-link.destroy'))->assertSessionHas('success');
        $this->get($newer)->assertNotFound();

        // It works only while its owner is an owner, and cashiers can't make one.
        $this->post(route('reports.sheet-link'));
        $newest = session('sheet_link');
        $this->owner->update(['role' => 'cashier']);
        $this->get($newest)->assertNotFound();
        $this->actingAs($this->cashier)->post(route('reports.sheet-link'))->assertRedirect(route('pos.index'));
        $this->assertNull($this->cashier->fresh()->sheet_token);
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
