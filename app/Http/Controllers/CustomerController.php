<?php
namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// Customers and what each one still owes (utang): down payments not paid off yet. A balance is paid
// sale by sale, on the sale's own page, so every sale keeps its own payment history.
class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $owing = Sale::owing()->groupBy('customer_id')
            ->selectRaw('customer_id, SUM(total_amount - amount_paid) as owed, COUNT(*) as sales_owing');

        $customers = Customer::query()
            ->leftJoinSub($owing, 'owing', 'owing.customer_id', '=', 'customers.id')
            ->select('customers.*', DB::raw('COALESCE(owing.owed, 0) as owed'), DB::raw('COALESCE(owing.sales_owing, 0) as sales_owing'))
            ->selectSub(Sale::selectRaw('MAX(created_at)')->whereColumn('customer_id', 'customers.id'), 'last_sale_at')
            ->when(! $request->boolean('all'), fn ($q) => $q->where('owing.owed', '>', 0))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%' . trim($request->search) . '%';
                $q->where(fn ($w) => $w->where('customers.name', 'like', $term)->orWhere('customers.phone', 'like', $term));
            })
            ->orderByDesc('owed')->orderBy('customers.name')
            ->paginate(25)->withQueryString();

        $owedBy = fn ($query) => (float) $query->sum(DB::raw('total_amount - amount_paid'));
        $totalOwed = $owedBy(Sale::owing());
        $owingCount = Sale::owing()->whereNotNull('customer_id')->distinct()->count('customer_id');
        // Down payments taken before a customer was required: owed, but by no one named.
        $unnamedOwed = $owedBy(Sale::owing()->whereNull('customer_id'));

        return view('customers.index', compact('customers', 'totalOwed', 'owingCount', 'unnamedOwed'));
    }

    public function show(Customer $customer)
    {
        $sales = $customer->sales()->where('status', '!=', 'archived')
            ->with('items', 'user', 'payments.receivedBy')->latest()->get();
        $owing = $sales->filter(fn (Sale $s) => $s->status !== 'voided' && (float) $s->amount_paid < (float) $s->total_amount);
        $owed = $owing->sum(fn (Sale $s) => (float) $s->total_amount - (float) $s->amount_paid);

        // One dated history, newest first: each purchase, and each payment on one. In the same second
        // (a sale and its payment at the counter), the payment came after.
        $history = $sales->flatMap(fn (Sale $s) => $s->payments->map(fn ($p) => ['at' => $p->created_at, 'payment' => $p, 'sale' => $s]))
            ->concat($sales->map(fn (Sale $s) => ['at' => $s->created_at, 'payment' => null, 'sale' => $s]))
            ->sortByDesc(fn ($entry) => [$entry['at']->getTimestamp(), $entry['payment'] ? 1 : 0])->values();

        return view('customers.show', compact('customer', 'owing', 'owed', 'history'));
    }

    public function update(Request $request, Customer $customer)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'nullable|string|max:30',
        ]);
        $customer->update(['name' => trim($data['name']), 'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null]);

        return back()->with('success', "Saved {$customer->name}'s name and phone number.");
    }
}
