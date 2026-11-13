<?php
namespace App\Http\Controllers;

use App\Models\InventoryMovement;
use App\Models\Sale;
use App\Models\Shift;
use App\Services\Sales\SalesReport;
use Carbon\CarbonImmutable;

// The owner's dashboard: today against yesterday, this month against last month, what sells and who
// sells it (SalesReport, so the numbers match the Sales Report), what's running low, and the latest
// sales and stock changes.
class DashboardController extends Controller
{
    public function index()
    {
        $today = CarbonImmutable::today();
        $day = new SalesReport('day', $today);
        $month = new SalesReport('month', $today);
        // Today and this month so far, each against the same stretch before it (yesterday up to this
        // time, last month up to this day): a whole day or month against part of one always looks down.
        $now = CarbonImmutable::now();
        $before = function ($from, $to) {
            $row = Sale::where('status', 'completed')->whereBetween('created_at', [$from, $to])
                ->selectRaw('COUNT(*) as count, COALESCE(SUM(total_amount), 0) as revenue')->first();

            return ['count' => (int) $row->count, 'revenue' => (float) $row->revenue, 'average' => $row->count ? (float) $row->revenue / $row->count : 0.0];
        };
        $totals = [
            'today' => $day->totals(),
            'yesterday' => $before($today->subDay(), $now->subDay()),
            'month' => $month->totals(),
            'lastMonth' => $before($today->startOfMonth()->subMonthNoOverflow(), $now->subMonthNoOverflow()),
        ];
        $low = $month->lowStock();

        // Weekly / Monthly / Yearly chart: one query for the daily totals of the last 12 months, added
        // up here (a query per bar took 23 trips to the database).
        $daily = Sale::where('status', 'completed')->where('created_at', '>=', $today->subMonths(11)->startOfMonth())
            ->selectRaw('DATE(created_at) as day, SUM(total_amount) as total')->groupBy('day')->pluck('total', 'day')
            ->mapWithKeys(fn ($total, $day) => [(string) $day => (float) $total]);
        $sumDays = fn ($from, $to) => round($daily->filter(fn ($total, $day) => $day >= $from->toDateString() && $day <= $to->toDateString())->sum(), 2);
        $days = collect(range(6, 0))->map(fn ($i) => $today->subDays($i));
        $weeks = collect(range(7, 0))->map(fn ($i) => $today->subWeeks($i)->startOfWeek());
        $months = collect(range(11, 0))->map(fn ($i) => $today->startOfMonth()->subMonths($i));
        $chartSets = [
            'weekly' => ['labels' => $days->map->format('D j')->all(), 'data' => $days->map(fn ($d) => $daily[$d->toDateString()] ?? 0)->all()],
            'monthly' => ['labels' => $weeks->map(fn ($w) => $w->format('M j'))->all(), 'data' => $weeks->map(fn ($w) => $sumDays($w, $w->endOfWeek()))->all()],
            'yearly' => ['labels' => $months->map->format('M')->all(), 'data' => $months->map(fn ($m) => $sumDays($m, $m->endOfMonth()))->all()],
        ];

        return view('dashboard.index', [
            'totals' => $totals,
            'chartSets' => $chartSets,
            'categories' => $month->byCategory(),
            'best' => $month->bestSellers(5),
            'people' => $month->byStaff(),
            'payments' => $month->payments(),
            'low' => $low,
            'outOfStockCount' => $low->where('out', true)->count(),
            'lowStockCount' => $low->where('out', false)->count(),
            'recentSales' => Sale::with('user', 'customer')->withSum('items as pieces', 'quantity')->where('status', '!=', 'archived')->latest()->limit(8)->get(),
            'recentMovements' => InventoryMovement::with('variant.product', 'user')->latest('created_at')->limit(6)->get(),
            'onShift' => Shift::with('user')->where('status', 'open')->orderBy('opened_at')->get(),
            'open' => Sale::whereIn('status', ['pending', 'processing'])->selectRaw("SUM(status = 'pending') as pending, SUM(status = 'processing') as processing")->first(),
        ]);
    }
}
