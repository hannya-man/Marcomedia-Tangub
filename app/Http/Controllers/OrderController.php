<?php
namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    // Orders now live inside the combined Sales & Orders page (see
    // POSController::transactions). This redirect just catches anyone
    // hitting the old standalone /orders URL directly.
    public function index(Request $request)
    {
        return redirect()->route('sales.index', array_merge(['tab' => 'orders'], $request->query()));
    }

    public function markCompleted(Order $order)
    {
        $order->update(['status' => 'completed']);
        return back()->with('success', "Order {$order->order_number} marked completed.");
    }

    public function cancel(Order $order)
    {
        $order->update(['status' => 'cancelled']);
        return back()->with('success', "Order {$order->order_number} cancelled.");
    }

    // There was previously no way to actually create an Order record, which
    // is why the Orders tab always looked empty — this is that missing form.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'customer_name' => 'required|string|max:150',
            'items_summary' => 'nullable|string',
            'item_count' => 'required|integer|min:1',
            'total_amount' => 'required|numeric|min:0',
        ]);

        $order = Order::create($validated + [
            'order_number' => Order::generateOrderNumber(),
            'status' => 'pending',
        ]);

        return redirect()->route('sales.index', ['tab' => 'orders', 'selected' => $order->id])
            ->with('success', "Order {$order->order_number} created.");
    }
}
