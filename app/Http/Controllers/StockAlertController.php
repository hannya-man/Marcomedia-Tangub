<?php
namespace App\Http\Controllers;

use App\Models\StockAlert;
use App\Services\Inventory\StockAlertService;
use Illuminate\Support\Facades\Auth;

class StockAlertController extends Controller
{
    // "Mark as seen" only records who saw it. The alert stays until stock is fixed.
    public function acknowledge(StockAlert $alert, StockAlertService $alerts)
    {
        $alerts->acknowledge($alert, Auth::id());

        return back()->with('success', 'Alert marked as seen.');
    }
}
