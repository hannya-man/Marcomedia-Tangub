<?php
namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $today = now()->toDateString();

        $todaySales = Sale::whereDate('created_at', $today)
            ->where('status', 'completed')
            ->sum('total_amount');

        $todayTransactions = Sale::whereDate('created_at', $today)
            ->where('status', 'completed')
            ->count();

        $monthSales = Sale::whereMonth('created_at', now()->month)
            ->whereYear('created_at', now()->year)
            ->where('status', 'completed')
            ->sum('total_amount');

        // Low stock / out of stock variants - the whole point of variant-level tracking
        $lowStockVariants = ProductVariant::with('product')
            ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->where('status', 'active')
            ->orderBy('stock_quantity')
            ->limit(10)
            ->get();

        $outOfStockCount = ProductVariant::where('stock_quantity', '<=', 0)->count();
        $lowStockCount = ProductVariant::whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->where('stock_quantity', '>', 0)
            ->count();
        $totalStockItems = ProductVariant::count();

        // Sales trend, last 7 days
        $salesTrend = Sale::selectRaw('DATE(created_at) as date, SUM(total_amount) as total')
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Best sellers this month
        $topProducts = SaleItem::selectRaw('item_name, SUM(quantity) as qty_sold, SUM(subtotal) as revenue')
            ->whereHas('sale', function ($q) {
                $q->whereMonth('created_at', now()->month)
                  ->where('status', 'completed');
            })
            ->groupBy('item_name')
            ->orderByDesc('qty_sold')
            ->limit(5)
            ->get();

        $recentSales = Sale::with('user')->latest()->limit(8)->get();

        // Replaces the old hardcoded "Recent Activity" feed with real stock movement history.
        $recentMovements = \App\Models\InventoryMovement::with('variant.product', 'user')
            ->latest('created_at')->limit(6)->get();

        // Real Weekly / Monthly / Yearly chart data — replaces the old
        // hardcoded placeholder numbers on the Sales Overview chart.
        $weeklyLabels = [];
        $weeklyData = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = now()->subDays($i);
            $weeklyLabels[] = $date->format('D');
            $weeklyData[] = (float) Sale::where('status', 'completed')
                ->whereDate('created_at', $date->toDateString())
                ->sum('total_amount');
        }

        $monthlyLabels = [];
        $monthlyData = [];
        for ($i = 3; $i >= 0; $i--) {
            $start = now()->subWeeks($i)->startOfWeek();
            $end = now()->subWeeks($i)->endOfWeek();
            $monthlyLabels[] = 'Week of ' . $start->format('M j');
            $monthlyData[] = (float) Sale::where('status', 'completed')
                ->whereBetween('created_at', [$start, $end])
                ->sum('total_amount');
        }

        $yearlyLabels = [];
        $yearlyData = [];
        for ($i = 11; $i >= 0; $i--) {
            $month = now()->subMonths($i);
            $yearlyLabels[] = $month->format('M');
            $yearlyData[] = (float) Sale::where('status', 'completed')
                ->whereYear('created_at', $month->year)
                ->whereMonth('created_at', $month->month)
                ->sum('total_amount');
        }

        $chartSets = [
            'weekly' => ['labels' => $weeklyLabels, 'data' => $weeklyData],
            'monthly' => ['labels' => $monthlyLabels, 'data' => $monthlyData],
            'yearly' => ['labels' => $yearlyLabels, 'data' => $yearlyData],
        ];

        return view('dashboard.index', compact(
            'todaySales', 'todayTransactions', 'monthSales',
            'lowStockVariants', 'outOfStockCount', 'lowStockCount', 'totalStockItems',
            'salesTrend', 'topProducts', 'recentSales', 'recentMovements', 'chartSets',
        ));
    }
}
