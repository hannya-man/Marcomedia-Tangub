<?php
namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Category;
use App\Models\Material;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\RejectedOutput;
use App\Models\Sale;
use App\Models\User;
use App\Services\Inventory\BatchInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Concerns\UsesTwoFactor;
use Tests\TestCase;

/**
 * A day in the shop, through the pages' own forms, as a cashier and as the owner: every action a
 * screen offers, then every page opened with the data that made. Collects every server error and
 * every time the stock stops balancing, instead of stopping at the first. Needs MySQL or MariaDB.
 */
class ShopDayTest extends TestCase
{
    use RefreshDatabase, UsesTwoFactor;

    private array $problems = [];
    private BatchInventoryService $inventory;

    private function check(string $what, $response, bool $expectError = false): void
    {
        $status = $response->getStatusCode();
        if ($status >= 500) {
            $e = $response->exception;
            $this->problems[] = "$what -> $status " . ($e ? get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : '');
            return;
        }
        $session = $response->baseResponse->getSession() ?? session();
        $error = session('error');
        $errors = session('errors');
        if (! $expectError && ($error || ($errors && $errors->any()))) {
            $this->problems[] = "$what -> refused: " . ($error ?: implode(' ', $errors->all()));
        }
        if ($expectError && ! $error && ! ($errors && $errors->any())) {
            $this->problems[] = "$what -> expected a refusal, got none (status $status)";
        }
        $audit = $this->inventory->auditBatches();
        if ($audit) {
            $this->problems[] = "$what -> stock no longer balances: " . json_encode($audit);
        }
        session()->forget(['error', 'success', 'errors']);
    }

    private function page(string $what, string $url): void
    {
        $r = $this->get($url);
        if ($r->getStatusCode() >= 500) {
            $e = $r->exception;
            $this->problems[] = "GET $what ($url) -> {$r->getStatusCode()} " . ($e ? get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() : '');
        }
    }

    public function test_a_day_in_the_shop_has_no_server_errors_and_stock_balances(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Needs MySQL or MariaDB.');
        }
        $this->inventory = app(BatchInventoryService::class);
        $owner = $this->withTwoFactor(User::create(['name' => 'Owner', 'email' => 'owner@example.com', 'password' => 'x', 'role' => 'admin']));
        $cashier = User::create(['name' => 'Cashier', 'email' => 'cashier@example.com', 'password' => 'x', 'role' => 'cashier']);

        // ---------------------------------------------------------------- the cashier's day
        $this->actingAs($cashier);
        $this->check('add continuous material', $this->post(route('stock.store'), ['inventory_type' => 'continuous', 'name' => 'Test Ink', 'unit' => 'ml',
            'opening_stock' => 1000, 'low_stock_threshold' => 100, 'cost_per_unit' => 2]));
        $ink = Material::where('name', 'Test Ink')->firstOrFail();
        $this->check('record use', $this->post(route('stock.pull', $ink), ['quantity' => 50, 'note' => 'Shirt run']));
        $this->check('record loss', $this->post(route('stock.loss', $ink), ['quantity' => 5, 'reason' => 'Spilled']));
        $this->check('count down', $this->post(route('stock.count', $ink), ['counted' => 900, 'reason' => 'Recount']));
        $this->check('count up', $this->post(route('stock.count', $ink), ['counted' => 920]));
        $this->check('restock to warehouse', $this->post(route('materials.restock', $ink), ['quantity' => 500, 'location' => 'warehouse']));
        $this->check('move from warehouse', $this->post(route('stock.transfer', $ink)));
        $this->check('start next pack', $this->post(route('stock.open-next', $ink), ['write_off_reason' => 'Dried out']));
        $this->check('record use more than in stock', $this->post(route('stock.pull', $ink), ['quantity' => 99999]), true);
        $this->check('record use of a discrete material', $this->post(route('stock.pull', Material::firstOrCreate(['name' => 'Tmp Discrete'], ['unit' => 'pcs', 'inventory_type' => 'discrete', 'stock_quantity' => 0])), ['quantity' => 1]), true);

        $this->check('add discrete material', $this->post(route('stock.store'), ['inventory_type' => 'discrete', 'name' => 'Test Blank', 'unit' => 'pcs', 'opening_stock' => 20]));
        $blank = Material::where('name', 'Test Blank')->firstOrFail();
        $this->check('add a material whose name is taken', $this->post(route('stock.store'), ['inventory_type' => 'discrete', 'name' => 'Keychain Blank', 'unit' => 'pcs', 'opening_stock' => 3]), true);
        $this->check('same name in other capitals and spaces', $this->post(route('stock.store'), ['inventory_type' => 'continuous', 'name' => '  test ink ', 'unit' => 'ml']), true);
        $this->check('loss with no open batch', $this->post(route('stock.loss', Material::where('name', 'Tmp Discrete')->first()), ['quantity' => 1, 'reason' => 'x']), true);

        // A board and a roll from the price lists the migrations add.
        $board = Material::where('sheet_kind', 'board')->whereNull('cut_from_id')->firstOrFail();
        $piece = Material::where('cut_from_id', $board->id)->whereNull('archived_at')->firstOrFail();
        $this->check('restock boards', $this->post(route('stock.restock-sheets', $board), ['sheets' => 3, 'location' => 'store']));
        $this->check('cut boards', $this->post(route('stock.cut-boards', $piece), ['boards' => 1]));
        $this->check('write off a board', $this->post(route('stock.write-off-board', $board), ['reason' => 'Cracked']));
        $this->check('add a cut size', $this->post(route('stock.cut-sizes.store', $board), ['label' => 'Tag', 'width' => 5, 'height' => 7, 'unit' => 'in']));
        $tag = Material::where('cut_from_id', $board->id)->where('name', 'like', '%Tag%')->first() ?? Material::where('cut_from_id', $board->id)->latest('id')->first();
        $this->check('remove the cut size', $this->delete(route('stock.cut-sizes.destroy', $tag)));
        $this->check('cut more boards than there are', $this->post(route('stock.cut-boards', $piece), ['boards' => 100]), true);
        $roll = Material::where('sheet_kind', 'roll')->whereNull('cut_from_id')->first();
        if ($roll) {
            $this->check('restock rolls', $this->post(route('stock.restock-sheets', $roll), ['sheets' => 1, 'location' => 'warehouse']));
            $this->check('move a roll from the warehouse', $this->post(route('stock.transfer', $roll)));
        }

        $this->check('record rejected output', $this->post(route('stock.rejected.store'), ['item_name' => 'Shirt', 'quantity' => 2, 'cause' => 'machine',
            'reason' => 'Misprint', 'lines' => [['material_id' => $ink->id, 'quantity' => 10]]]));
        $this->check('record rejected output sold as scrap', $this->post(route('stock.rejected.store'), ['item_name' => 'Mug', 'quantity' => 1, 'cause' => 'process',
            'reason' => 'Smudged', 'lines' => [['material_id' => $blank->id, 'quantity' => 1]], 'scrap_revenue' => 30, 'payment_method' => 'cash']));
        $first = RejectedOutput::orderBy('id')->first();
        $this->check('sell a reject as scrap', $this->post(route('stock.rejected.scrap', $first), ['scrap_revenue' => 20, 'payment_method' => 'gcash']));
        $this->check('sell it again', $this->post(route('stock.rejected.scrap', $first), ['scrap_revenue' => 20, 'payment_method' => 'cash']), true);
        $this->check('record another reject', $this->post(route('stock.rejected.store'), ['item_name' => 'Cap', 'quantity' => 1, 'cause' => 'other',
            'reason' => 'Wrong color', 'lines' => [['material_id' => $ink->id, 'quantity' => 1]]]));
        $this->check('discard it', $this->post(route('stock.rejected.discard', RejectedOutput::latest('id')->first())));

        // Products: a stocked size made from blanks (producing it uses them), and a made-to-order one.
        $this->check('add category', $this->post(route('categories.store'), ['name' => 'Giveaways']));
        $category = Category::where('name', 'Giveaways')->firstOrFail();
        $keychain = Product::create(['name' => 'Test Keychain', 'sku' => 'KC-1', 'base_price' => 60, 'has_variants' => true, 'track_inventory' => true, 'status' => 'active', 'category_id' => $category->id]);
        $kcSize = $keychain->variants()->create(['variant_name' => 'Round', 'sku' => 'KC-1-R', 'price' => 60, 'stock_quantity' => 0, 'status' => 'active']);
        $kcSize->materials()->attach($blank->id, ['quantity_per_unit' => 1, 'consumption_type' => 'fixed']);
        $mug = Product::create(['name' => 'Photo Mug', 'sku' => 'MUG-1', 'base_price' => 150, 'has_variants' => true, 'track_inventory' => false, 'status' => 'active']);
        $mugSize = $mug->variants()->create(['variant_name' => 'Standard', 'sku' => 'MUG-1-S', 'price' => 150, 'stock_quantity' => 0, 'status' => 'active']);
        $mugSize->materials()->attach($blank->id, ['quantity_per_unit' => 1, 'consumption_type' => 'fixed']);

        $this->check('restock a stocked size (uses blanks)', $this->post(route('inventory.adjust', $kcSize), ['type' => 'stock_in', 'quantity' => 5]));
        $this->check('restock more than the blanks allow', $this->post(route('inventory.adjust', $kcSize), ['type' => 'stock_in', 'quantity' => 500]), true);
        $this->check('mark damaged', $this->post(route('inventory.mark-damaged', $kcSize), ['quantity' => 1, 'remarks' => 'Scratched']));
        $this->check('mark more damaged than in stock', $this->post(route('inventory.mark-damaged', $kcSize), ['quantity' => 999]), true);

        // Sales: paid in full, a down payment paid off later, one out of stock, a void request.
        $sell = fn ($size, $product, $qty, $paid, $price) => $this->post(route('pos.store'), [
            'items' => [['product_id' => $product->id, 'product_variant_id' => $size->id, 'quantity' => $qty, 'unit_price' => $price]],
            'payment_method' => 'cash', 'amount_paid' => $paid, 'new_customer_name' => 'Ana', 'new_customer_phone' => '0917']);
        $this->check('sell a stocked item', $sell($kcSize, $keychain, 2, 120, 60));
        $paidSale = Sale::latest('id')->first();
        $this->check('sell made-to-order on a down payment', $sell($mugSize, $mug, 3, 100, 150));
        $downSale = Sale::latest('id')->first();
        $this->check('sell more than in stock (goes on order)', $sell($mugSize, $mug, 50, 7500, 150));
        $orderSale = Sale::latest('id')->first();
        $this->check('pay off the balance', $this->patch(route('sales.update', $downSale), ['status' => 'pending', 'additional_payment' => 350]));
        $this->check('complete it', $this->post(route('sales.complete', $downSale)));
        $this->check('complete the paid sale', $this->post(route('sales.complete', $paidSale)));
        $this->check('complete one still on order', $this->post(route('sales.complete', $orderSale)), true);
        $this->check('take stock for the order', $this->post(route('sales.take-stock', $orderSale)), true);
        $this->check('ask to void', $this->post(route('sales.void', $orderSale), ['reason' => 'Customer cancelled']));
        $this->check('cancel own request', $this->post(route('approvals.cancel', ApprovalRequest::latest('id')->first())));
        $this->check('ask to void again', $this->post(route('sales.void', $orderSale), ['reason' => 'Customer cancelled']));
        $this->check('cashier archives a category', $this->delete(route('categories.destroy', $category)));
        $this->check('cashier restores it', $this->post(route('categories.restore', $category)));

        foreach (['pos.index', 'inventory.index', 'inventory.low-stock', 'inventory.archive', 'inventory.categories', 'categories.archive', 'stock.continuous',
            'stock.discrete', 'stock.rejected', 'materials.archive', 'sales.index', 'approvals.index', 'profile.edit', 'two-factor.show'] as $name) {
            $this->page("cashier $name", route($name));
        }

        // ---------------------------------------------------------------- the owner's day
        $this->actingAs($owner);
        $this->check('approve the void', $this->post(route('approvals.approve', ApprovalRequest::where('status', 'pending')->latest('id')->first())));
        $this->check('sell again', $sell($kcSize, $keychain, 1, 60, 60));
        $toVoid = Sale::latest('id')->first();
        $this->check('owner voids', $this->post(route('sales.void', $toVoid)));
        $this->check('archive a sale', $this->post(route('sales.archiveOne', $paidSale)));
        $this->check('restore it', $this->post(route('sales.restore', $paidSale)));
        $this->check('archive a product', $this->delete(route('inventory.destroy', $mug)));
        $this->check('restore it', $this->post(route('inventory.restore', $mug)));
        $this->check('archive a material used by a product', $this->delete(route('materials.destroy', $blank)));
        $this->check('sell a product made from the archived material', $sell($mugSize, $mug, 1, 150, 150), true);
        $this->check('restore the material', $this->post(route('materials.restore', $blank)));
        $this->check('add a cashier', $this->post(route('users.store'), ['name' => 'Ben', 'email' => 'ben@example.com', 'role' => 'cashier', 'password' => 'secret123']));

        // ---------------------------------------------------------------- every page, with this data
        foreach ([$cashier, $owner] as $who) {
            $this->actingAs($who);
            $r = $who->role;
            foreach (['pos.index', 'inventory.index', 'inventory.low-stock', 'inventory.archive', 'inventory.categories', 'categories.archive', 'stock.continuous',
                'stock.discrete', 'stock.rejected', 'materials.archive', 'sales.index', 'approvals.index', 'profile.edit', 'two-factor.show'] as $name) {
                $this->page("$r $name", route($name));
            }
            $this->page("$r materials archive from discrete", route('materials.archive', ['from' => 'discrete']));
            $this->page("$r rejected with record", route('stock.rejected', ['record' => $ink->id, 'month' => now()->format('Y-m')]));
            foreach (['pending', 'processing', 'completed', 'voided', 'on_order', 'archived'] as $status) {
                $this->page("$r sales $status", route('sales.index', ['status' => $status, 'search' => 'Ana', 'date_from' => now()->subMonth()->toDateString(), 'date_to' => now()->toDateString()]));
            }
            foreach (Sale::all() as $sale) {
                $this->page("$r receipt {$sale->invoice_number}", route('pos.receipt', $sale));
                $this->page("$r edit sale {$sale->invoice_number}", route('sales.index', ['edit_sale' => $sale->id]));
            }
            foreach (Product::all() as $product) {
                $this->page("$r edit {$product->name}", route('inventory.edit', $product));
            }
            $this->page("$r inventory search", route('inventory.index', ['search' => 'Key', 'category_id' => $category->id]));
            if ($r === 'admin') {
                foreach (['dashboard', 'users.index', 'sales.archive'] as $name) {
                    $this->page("admin $name", route($name));
                }
                $this->page('admin users edit', route('users.index', ['edit' => $cashier->id, 'search' => 'Cash']));
            }
        }

        $this->assertSame([], $this->problems, "Problems found:\n" . implode("\n", $this->problems));
    }
}
