<?php
namespace App\Services\Sales;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\CarbonPeriod;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What sold, and for how much, in one day, week, month or year. Like the dashboard, a sale counts once
 * it's completed (made and released), on the day it was rung up; voided and archived ones never do.
 * Money received is every payment taken in the period on any sale that wasn't voided: down payments
 * and balances paid later included.
 */
class SalesReport
{
    public const PERIODS = ['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'];

    public CarbonImmutable $start;
    public CarbonImmutable $end;

    public function __construct(public string $period, CarbonImmutable $date)
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

    public function byStaff(): Collection
    {
        return $this->sales()->join('users', 'users.id', '=', 'sales.user_id')->groupBy('users.id', 'users.name')
            ->select('users.name', DB::raw('COUNT(*) as count'), DB::raw('SUM(sales.total_amount) as revenue'))
            ->orderByDesc('revenue')->get();
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
            ->groupBy('sale_payments.method')
            ->selectRaw('sale_payments.method, COUNT(*) as count, SUM(sale_payments.amount) as total')
            ->get()->keyBy('method');
    }

    public function hourLabel(int $hour): string
    {
        return $this->start->setTime($hour, 0)->format('g A');
    }

    // The whole report as spreadsheet rows (ReportController::download).
    public function rows(): array
    {
        $totals = $this->totals();
        $rows = [
            ['Sales report', $this->label()],
            ['Completed sales', $totals['count']],
            ['Sales total', round($totals['revenue'], 2)],
            ['Discounts given', round($totals['discounts'], 2)],
            ['Average sale', round($totals['average'], 2)],
            [],
            ['Money received', 'Payments', 'Amount'],
        ];
        foreach ($this->payments() as $method => $p) {
            $rows[] = [$method === 'gcash' ? 'GCash' : ucfirst($method), (int) $p->count, round((float) $p->total, 2)];
        }
        $series = $this->series();
        $rows[] = [];
        $rows[] = [match ($this->period) { 'day' => 'Hour', 'year' => 'Month', default => 'Day' }, 'Sales total'];
        foreach ($series['labels'] as $i => $label) {
            $rows[] = [$label, round($series['values'][$i], 2)];
        }
        $rows[] = [];
        $rows[] = ['Item', 'Size or type', 'SKU', 'Quantity', 'Revenue'];
        foreach ($this->byItem() as $item) {
            $rows[] = [$item->name, $item->variant_name, $item->sku, (int) $item->quantity, round((float) $item->revenue, 2)];
        }
        $rows[] = [];
        $rows[] = ['Category', 'Revenue (before discounts)'];
        foreach ($this->byCategory() as $category) {
            $rows[] = [$category->name, round((float) $category->revenue, 2)];
        }
        $rows[] = [];
        $rows[] = ['Staff', 'Sales', 'Revenue'];
        foreach ($this->byStaff() as $person) {
            $rows[] = [$person->name, (int) $person->count, round((float) $person->revenue, 2)];
        }
        $rows[] = [];
        $rows[] = ['Hour', 'Sales'];
        foreach ($this->busiestHours()->sortKeys() as $hour => $count) {
            $rows[] = [$this->hourLabel((int) $hour), (int) $count];
        }

        return $rows;
    }

    private function sales()
    {
        return DB::table('sales')->where('sales.status', 'completed')->whereBetween('sales.created_at', [$this->start, $this->end]);
    }

    private function lines()
    {
        return DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.status', 'completed')->whereBetween('sales.created_at', [$this->start, $this->end]);
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
