<?php
namespace App\Services\Sales;

use App\Models\Material;
use App\Models\ProductVariant;
use App\Models\RejectedOutput;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What sold, and for how much, in one day, week, month or year, for everyone or one person. Like the
 * dashboard, a sale counts once it's completed (made and released), on the day it was rung up; voided
 * and archived ones never do. Money received is every payment taken in the period on any sale that
 * wasn't voided: down payments and balances paid later included.
 * Beside the sales: the materials used (continuous and discrete), what was lost (damaged, wasted,
 * written off, rejected runs) and, as of now, what's running low.
 */
class SalesReport
{
    public const PERIODS = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    // Every table of the report, in order: the spreadsheet's sheets, the CSV's sections, and what a
    // Google Sheets link can bring in (table()).
    public const TABLES = [
        'summary' => 'Summary', 'sales' => 'Sales', 'items' => 'Items sold', 'by_item' => 'By item',
        'staff' => 'By staff', 'staff_items' => 'What each person sold', 'categories' => 'By category',
        'payments' => 'Money received', 'by_time' => 'By time', 'materials' => 'Materials used',
        'waste' => 'Waste', 'rejected' => 'Rejected runs', 'low_stock' => 'Low stock',
    ];

    private const LOSSES = ['damaged' => 'Damaged', 'wasted' => 'Wasted', 'write_off' => 'Written off'];

    public CarbonImmutable $start;
    public CarbonImmutable $end;

    public function __construct(public string $period, CarbonImmutable $date, public ?int $userId = null)
    {
        [$this->start, $this->end] = match ($period) {
            'week' => [$date->startOfWeek(CarbonInterface::MONDAY), $date->endOfWeek(CarbonInterface::SUNDAY)],
            'month' => [$date->startOfMonth(), $date->endOfMonth()],
            'year' => [$date->startOfYear(), $date->endOfYear()],
            default => [$date->startOfDay(), $date->endOfDay()],
        };
    }

    public function label(): string
    {
        return match ($this->period) {
            'week' => $this->start->format($this->start->year === $this->end->year ? 'M j' : 'M j, Y') . ' – '
                . $this->end->format($this->start->month === $this->end->month ? 'j, Y' : 'M j, Y'),
            'month' => $this->start->format('F Y'),
            'year' => $this->start->format('Y'),
            default => $this->start->format('l, F j, Y'),
        };
    }

    // The same length of time before this one, and after it (none past today).
    public function previous(): string
    {
        return $this->shift(-1)->toDateString();
    }

    public function next(): ?string
    {
        $next = $this->shift(1);

        return $next->isFuture() ? null : $next->toDateString();
    }

    public function totals(): array
    {
        $row = $this->sales()->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue, COALESCE(SUM(discount_amount), 0) as discounts')->first();

        return [
            'count' => (int) $row->count,
            'revenue' => (float) $row->revenue,
            'discounts' => (float) $row->discounts,
            'average' => $row->count ? (float) $row->revenue / $row->count : 0.0,
            'items' => (int) $this->saleLines()->sum('sale_items.quantity'),
        ];
    }

    // Sales by hour (a day), by day (a week or month) or by month (a year): ['labels' => [], 'values' => []].
    public function series(): array
    {
        [$bucket, $keys, $label] = match ($this->period) {
            'day' => ['HOUR(created_at)', range(0, 23), fn ($h) => $this->hourLabel($h)],
            'year' => ['MONTH(created_at)', range(1, 12), fn ($m) => $this->start->setMonth($m)->format('M')],
            default => ['DATE(created_at)', collect(CarbonPeriod::create($this->start, $this->end))->map->toDateString()->all(),
                fn ($d) => CarbonImmutable::parse($d)->format($this->period === 'week' ? 'D j' : 'j')],
        };
        $totals = $this->sales()->selectRaw("{$bucket} as bucket, SUM(total_amount) as total")->groupBy('bucket')
            ->pluck('total', 'bucket')->mapWithKeys(fn ($total, $key) => [(string) $key => (float) $total]);

        return [
            'labels' => array_map($label, $keys),
            'values' => array_map(fn ($key) => $totals[(string) $key] ?? 0.0, $keys),
        ];
    }

    public function bestSellers(int $limit = 10): Collection
    {
        return $this->lines()->groupBy('products.id', 'products.name')
            ->select('products.name', DB::raw('SUM(sale_items.quantity) as quantity'), DB::raw('SUM(sale_items.subtotal) as revenue'))
            ->orderByDesc('quantity')->limit($limit)->get();
    }

    // Every item and size sold, by revenue.
    public function byItem(): Collection
    {
        return $this->lines()->leftJoin('product_variants as pv', 'pv.id', '=', 'sale_items.product_variant_id')
            ->groupBy('products.id', 'products.name', 'products.sku', 'pv.id', 'pv.variant_name', 'pv.sku')
            ->select('products.name', 'pv.variant_name', DB::raw('COALESCE(pv.sku, products.sku) as sku'),
                DB::raw('SUM(sale_items.quantity) as quantity'), DB::raw('SUM(sale_items.subtotal) as revenue'))
            ->orderByDesc('revenue')->get();
    }

    // Item prices, before any discount on the whole sale.
    public function byCategory(): Collection
    {
        return $this->lines()->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->groupBy('categories.id', 'categories.name')
            ->select(DB::raw("COALESCE(categories.name, 'No category') as name"), DB::raw('SUM(sale_items.subtotal) as revenue'))
            ->orderByDesc('revenue')->get();
    }

    // Each person: sales rung up, pieces sold, revenue (after discounts) and the average sale.
    public function byStaff(): Collection
    {
        $items = $this->saleLines()->groupBy('sales.user_id')
            ->selectRaw('sales.user_id, SUM(sale_items.quantity) as items')->pluck('items', 'user_id');

        return $this->sales()->join('users', 'users.id', '=', 'sales.user_id')->groupBy('users.id', 'users.name')
            ->select('users.id', 'users.name', DB::raw('COUNT(*) as count'), DB::raw('SUM(sales.total_amount) as revenue'),
                DB::raw('SUM(sales.discount_amount) as discounts'))
            ->orderByDesc('revenue')->get()
            ->each(function ($person) use ($items) {
                $person->items = (int) ($items[$person->id] ?? 0);
                $person->average = (float) $person->revenue / max((int) $person->count, 1);
            });
    }

    // What each person sold: person, item and size, with the pieces and revenue.
    public function staffItems(): Collection
    {
        return $this->saleLines()->join('users', 'users.id', '=', 'sales.user_id')
            ->groupBy('users.id', 'users.name', 'sale_items.item_name', 'sale_items.variant_name')
            ->select('users.name as staff', 'sale_items.item_name as name', 'sale_items.variant_name',
                DB::raw('SUM(sale_items.quantity) as quantity'), DB::raw('SUM(sale_items.subtotal) as revenue'))
            ->orderBy('users.name')->orderByDesc('revenue')->get();
    }

    // Every sale, newest first, with who rang it up and how many pieces (a query: the page pages it).
    public function salesList()
    {
        return $this->sales()->join('users', 'users.id', '=', 'sales.user_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->select('sales.id', 'sales.invoice_number', 'sales.created_at', 'sales.total_amount', 'sales.discount_amount',
                'sales.payment_method', 'users.name as staff', 'customers.name as customer')
            ->selectSub(DB::table('sale_items')->selectRaw('COALESCE(SUM(quantity), 0)')->whereColumn('sale_id', 'sales.id'), 'items')
            ->orderByDesc('sales.created_at')->orderByDesc('sales.id');
    }

    // How many sales were rung up in each hour of the day: [hour => count], busiest first.
    public function busiestHours(): Collection
    {
        return $this->sales()->selectRaw('HOUR(created_at) as hour, COUNT(*) as count')->groupBy('hour')
            ->orderByDesc('count')->orderBy('hour')->pluck('count', 'hour');
    }

    // Money received, by how it was paid: ['cash' => {count, total}, 'gcash' => ...].
    public function payments(): Collection
    {
        return DB::table('sale_payments')->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->where('sales.status', '!=', 'voided')
            ->whereBetween('sale_payments.created_at', [$this->start, $this->end])
            ->when($this->userId, fn ($q) => $q->where('sales.user_id', $this->userId))
            ->groupBy('sale_payments.method')
            ->selectRaw('sale_payments.method, COUNT(*) as count, SUM(sale_payments.amount) as total')
            ->get()->keyBy('method');
    }

    // Materials taken in this time, by sales and by staff pulls, at each batch's cost. A voided
    // sale's materials went back on the shelf, so they don't count.
    public function materialsUsed(): Collection
    {
        return DB::table('batch_consumptions as bc')
            ->join('inventory_batches as b', 'b.id', '=', 'bc.inventory_batch_id')
            ->join('materials as m', 'm.id', '=', 'b.material_id')
            ->leftJoin('sales', 'sales.id', '=', 'bc.sale_id')
            ->where(fn ($q) => $q->whereNull('bc.sale_id')->orWhere('sales.status', '!=', 'voided'))
            ->whereBetween('bc.created_at', [$this->start, $this->end])
            ->when($this->userId, fn ($q) => $q->where('bc.user_id', $this->userId))
            ->groupBy('m.id', 'm.name', 'm.unit', 'm.inventory_type')
            ->select('m.name', 'm.unit', 'm.inventory_type', DB::raw('SUM(bc.quantity_consumed) as quantity'),
                DB::raw('SUM(bc.quantity_consumed * COALESCE(b.cost_per_unit, m.cost_per_unit, 0)) as cost'))
            ->orderByDesc('cost')->orderBy('m.name')->get();
    }

    // Material lost: marked damaged, wasted (a rejected run's materials too) or written off.
    public function waste(): Collection
    {
        return DB::table('batch_adjustments as a')
            ->join('inventory_batches as b', 'b.id', '=', 'a.inventory_batch_id')
            ->join('materials as m', 'm.id', '=', 'b.material_id')
            ->whereIn('a.type', array_keys(self::LOSSES))
            ->whereBetween('a.created_at', [$this->start, $this->end])
            ->when($this->userId, fn ($q) => $q->where('a.user_id', $this->userId))
            ->groupBy('m.id', 'm.name', 'm.unit', 'm.inventory_type', 'a.type')
            ->select('m.name', 'm.unit', 'm.inventory_type', 'a.type', DB::raw('SUM(-a.quantity) as quantity'),
                DB::raw('SUM(-a.quantity * COALESCE(b.cost_per_unit, m.cost_per_unit, 0)) as cost'))
            ->orderByDesc('cost')->get()
            ->each(fn ($row) => $row->what = self::LOSSES[$row->type]);
    }

    // Failed runs and reprints recorded in this time (Scrapped / Rejected Output).
    public function rejected(): Collection
    {
        return DB::table('rejected_outputs')
            ->whereBetween('created_at', [$this->start, $this->end])
            ->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get(['created_at', 'reference', 'item_name', 'quantity', 'cause', 'material_cost', 'status', 'scrap_revenue']);
    }

    // Running low or out right now (not for the period): materials, then stocked product sizes.
    // A made-to-order size has no count of its own, so it's never on this list (as on the dashboard).
    public function lowStock(): Collection
    {
        $materials = Material::whereNull('archived_at')->whereNull('cut_from_id')->with('cutSizes')->orderBy('name')->get()
            ->filter(fn ($m) => $m->stock_status !== 'in_stock')
            ->map(fn ($m) => (object) [
                'kind' => $m->inventory_type === 'continuous' ? 'Continuous raw material' : 'Discrete material',
                'name' => $m->name, 'unit' => $m->unit, 'out' => $m->stock_status === 'out_of_stock',
                'on_hand' => (float) $m->stock_quantity, 'reorder_at' => (float) $m->low_stock_threshold,
            ]);
        $products = ProductVariant::with('product:id,name')->where('status', 'active')
            ->whereHas('product', fn ($q) => $q->where('status', 'active')->where('track_inventory', true))
            ->whereRaw('(stock_quantity - damaged_quantity) <= low_stock_threshold')
            ->orderByRaw('stock_quantity - damaged_quantity')->get()
            ->map(fn ($v) => (object) [
                'kind' => 'Product', 'name' => "{$v->product->name} ({$v->variant_name})", 'unit' => 'pcs',
                'out' => $v->sellable_quantity <= 0, 'on_hand' => (float) $v->sellable_quantity, 'reorder_at' => (float) $v->low_stock_threshold,
            ]);

        return $materials->sortBy('on_hand')->values()->concat($products);
    }

    public function hourLabel(int $hour): string
    {
        return $this->start->setTime($hour, 0)->format('g A');
    }

    // One table of the report as spreadsheet rows, headings first.
    public function table(string $key): array
    {
        $money = fn ($value) => round((float) $value, 2);
        $qty = fn ($value) => round((float) $value, 3);
        $time = fn ($at) => CarbonImmutable::parse($at);

        return match ($key) {
            'summary' => (function () use ($money) {
                $totals = $this->totals();
                $payments = $this->payments();
                $used = $this->materialsUsed();
                $rejected = $this->rejected();

                return [
                    ['Sales report', $this->label()],
                    ['Staff', $this->staffName() ?? 'Everyone'],
                    ['Sales total', $money($totals['revenue'])],
                    ['Completed sales', $totals['count']],
                    ['Average sale', $money($totals['average'])],
                    ['Items sold', $totals['items']],
                    ['Discounts given', $money($totals['discounts'])],
                    ['Money received', $money($payments->sum('total'))],
                    ['Continuous raw materials used (cost)', $money($used->where('inventory_type', 'continuous')->sum('cost'))],
                    ['Discrete materials used (cost)', $money($used->where('inventory_type', '!=', 'continuous')->sum('cost'))],
                    ['Material lost (cost)', $money($this->waste()->sum('cost'))],
                    ['Rejected runs', $rejected->count()],
                    ['Scrap sold', $money($rejected->sum('scrap_revenue'))],
                ];
            })(),
            'sales' => [['Date', 'Time', 'Invoice', 'Staff', 'Customer', 'Items', 'Discount', 'Total', 'Paid by'],
                ...$this->salesList()->get()->map(fn ($s) => [$time($s->created_at)->toDateString(), $time($s->created_at)->format('g:i A'),
                    $s->invoice_number, $s->staff, $s->customer, (int) $s->items, $money($s->discount_amount), $money($s->total_amount),
                    $s->payment_method === 'gcash' ? 'GCash' : ucfirst((string) $s->payment_method)])],
            'items' => [['Date', 'Time', 'Invoice', 'Staff', 'Customer', 'Item', 'Size or type', 'SKU', 'Category', 'Quantity', 'Unit price', 'Line total'],
                ...$this->itemLines()->map(fn ($l) => [$time($l->created_at)->toDateString(), $time($l->created_at)->format('g:i A'),
                    $l->invoice_number, $l->staff, $l->customer, $l->item_name, $l->variant_name, $l->sku, $l->category,
                    (int) $l->quantity, $money($l->unit_price), $money($l->subtotal)])],
            'by_item' => [['Item', 'Size or type', 'SKU', 'Quantity', 'Revenue'],
                ...$this->byItem()->map(fn ($i) => [$i->name, $i->variant_name, $i->sku, (int) $i->quantity, $money($i->revenue)])],
            'staff' => [['Staff', 'Sales', 'Items sold', 'Revenue', 'Average sale', 'Discounts given'],
                ...$this->byStaff()->map(fn ($p) => [$p->name, (int) $p->count, $p->items, $money($p->revenue), $money($p->average), $money($p->discounts)])],
            'staff_items' => [['Staff', 'Item', 'Size or type', 'Quantity', 'Revenue'],
                ...$this->staffItems()->map(fn ($r) => [$r->staff, $r->name, $r->variant_name, (int) $r->quantity, $money($r->revenue)])],
            'categories' => [['Category', 'Revenue (before discounts)'],
                ...$this->byCategory()->map(fn ($c) => [$c->name, $money($c->revenue)])],
            'payments' => [['Paid by', 'Payments', 'Amount'],
                ...$this->payments()->map(fn ($p, $method) => [$method === 'gcash' ? 'GCash' : ucfirst($method), (int) $p->count, $money($p->total)])->values()],
            'by_time' => (function () use ($money) {
                $series = $this->series();

                return [[match ($this->period) { 'day' => 'Hour', 'year' => 'Month', default => 'Day' }, 'Sales total'],
                    ...array_map(fn ($label, $value) => [$label, $money($value)], $series['labels'], $series['values'])];
            })(),
            'materials' => [['Type', 'Material', 'Used', 'Unit', 'Cost'],
                ...$this->materialsUsed()->map(fn ($m) => [$this->typeName($m->inventory_type), $m->name, $qty($m->quantity), $m->unit, $money($m->cost)])],
            'waste' => [['Type', 'Material', 'What happened', 'Quantity', 'Unit', 'Cost'],
                ...$this->waste()->map(fn ($w) => [$this->typeName($w->inventory_type), $w->name, $w->what, $qty($w->quantity), $w->unit, $money($w->cost)])],
            'rejected' => [['Date', 'Reference', 'Item', 'Quantity', 'Cause', 'Material cost', 'Status', 'Scrap sold for'],
                ...$this->rejected()->map(fn ($r) => [$time($r->created_at)->toDateString(), $r->reference, $r->item_name, (int) $r->quantity,
                    RejectedOutput::CAUSES[$r->cause] ?? $r->cause, $money($r->material_cost),
                    ['scrap_sold' => 'Sold as scrap', 'discarded' => 'Thrown away'][$r->status] ?? 'Rejected', $money($r->scrap_revenue)])],
            'low_stock' => [['Type', 'Item or material', 'On hand', 'Unit', 'Reorder at', 'Status'],
                ...$this->lowStock()->map(fn ($s) => [$s->kind, $s->name, $qty($s->on_hand), $s->unit, $qty($s->reorder_at), $s->out ? 'Out' : 'Low'])],
        };
    }

    // The whole report: [sheet name => rows].
    public function tables(): array
    {
        return array_combine(self::TABLES, array_map(fn ($key) => $this->table($key), array_keys(self::TABLES)));
    }

    public function staffName(): ?string
    {
        return $this->userId ? DB::table('users')->where('id', $this->userId)->value('name') : null;
    }

    private function typeName(?string $type): string
    {
        return $type === 'continuous' ? 'Continuous raw material' : 'Discrete material';
    }

    // Every line sold, newest first, named as it was sold (scrap included).
    private function itemLines(): Collection
    {
        return $this->saleLines()->join('users', 'users.id', '=', 'sales.user_id')
            ->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')
            ->leftJoin('product_variants as pv', 'pv.id', '=', 'sale_items.product_variant_id')
            ->leftJoin('products', 'products.id', '=', 'sale_items.product_id')
            ->leftJoin('categories', 'categories.id', '=', 'products.category_id')
            ->select('sales.created_at', 'sales.invoice_number', 'users.name as staff', 'customers.name as customer',
                'sale_items.item_name', 'sale_items.variant_name', DB::raw('COALESCE(pv.sku, products.sku) as sku'),
                'categories.name as category', 'sale_items.quantity', 'sale_items.unit_price', 'sale_items.subtotal')
            ->orderByDesc('sales.created_at')->orderByDesc('sale_items.id')->get();
    }

    private function sales()
    {
        return DB::table('sales')->where('sales.status', 'completed')->whereBetween('sales.created_at', [$this->start, $this->end])
            ->when($this->userId, fn ($q) => $q->where('sales.user_id', $this->userId));
    }

    // Lines of the completed sales, scrap included.
    private function saleLines()
    {
        return DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.status', 'completed')->whereBetween('sales.created_at', [$this->start, $this->end])
            ->when($this->userId, fn ($q) => $q->where('sales.user_id', $this->userId));
    }

    // Lines of catalog products only.
    private function lines()
    {
        return $this->saleLines()->join('products', 'products.id', '=', 'sale_items.product_id');
    }

    private function shift(int $by): CarbonImmutable
    {
        return match ($this->period) {
            'week' => $this->start->addWeeks($by),
            'month' => $this->start->addMonthsNoOverflow($by),
            'year' => $this->start->addYears($by),
            default => $this->start->addDays($by),
        };
    }
}
