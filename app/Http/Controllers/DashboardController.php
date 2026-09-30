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

        // Low stock / out of stock variants — now driven by SELLABLE
        // stock (stock minus damaged), not raw stock_quantity.
        $lowStockVariants = ProductVariant::with('product')
            ->whereRaw('(stock_quantity - damaged_quantity) <= low_stock_threshold')
            ->where('status', 'active')
            ->orderByRaw('stock_quantity - damaged_quantity')
            ->limit(10)
            ->get();

        $outOfStockCount = ProductVariant::whereRaw('(stock_quantity - damaged_quantity) <= 0')->count();
        $lowStockCount = ProductVariant::whereRaw('(stock_quantity - damaged_quantity) <= low_stock_threshold')
            ->whereRaw('(stock_quantity - damaged_quantity) > 0')
            ->count();
        $totalStockItems = ProductVariant::count();

        // Sales trend, last 7 days
        $salesTrend = Sale::selectRaw('DATE(created_at) as date, SUM(total_amount) as total')
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(6)->startOfDay())
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Top 10 fast movers TODAY — grouped by product_id, not item_name.
        // Grouping by name silently merges two different products that
        // happen to share a name, and splits history for a renamed product.
        $topMovers = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->whereDate('sales.created_at', $today)
            ->where('sales.status', 'completed')
            ->select('products.id', 'products.name', DB::raw('SUM(sale_items.quantity) as qty_sold'), DB::raw('SUM(sale_items.subtotal) as revenue'))
            ->groupBy('products.id', 'products.name')
            ->orderByDesc('qty_sold')
            ->limit(10)
            ->get();

        // Bottom 5 slow movers TODAY — starts from the products table and
        // LEFT JOINs sales onto it, so a product with zero sales today
        // still shows up with qty_sold = 0. Starting from sale_items
        // instead would silently drop the slowest movers of all (the ones
        // that sold nothing), since they'd have no row to find.
        $soldToday = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereDate('sales.created_at', $today)
            ->where('sales.status', 'completed')
            ->select('sale_items.product_id', DB::raw('SUM(sale_items.quantity) as qty_sold'))
            ->groupBy('sale_items.product_id');

        $slowMovers = DB::table('products')
            ->leftJoinSub($soldToday, 'sold', 'sold.product_id', '=', 'products.id')
            ->where('products.status', 'active')
            ->select('products.id', 'products.name', DB::raw('COALESCE(sold.qty_sold, 0) as qty_sold'))
            ->orderBy('qty_sold')
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
            'salesTrend', 'topMovers', 'slowMovers', 'recentSales', 'recentMovements', 'chartSets',
        ));
    }
}
