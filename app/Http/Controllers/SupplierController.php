<?php
namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function store(Request $request)
    {
        $supplier = Supplier::create($request->validate($this->rules()));

        return back()->with('success', "Supplier {$supplier->name} added.");
    }

    public function update(Request $request, Supplier $supplier)
    {
        $supplier->update($request->validate($this->rules()));

        return back()->with('success', "{$supplier->name} updated.");
    }

    // Archive, same pattern as products and materials. Old POs keep their supplier.
    public function destroy(Supplier $supplier)
    {
        $supplier->update(['archived_at' => now()]);

        return back()->with('success', "{$supplier->name} archived.");
    }

    public function restore(Supplier $supplier)
    {
        $supplier->update(['archived_at' => null]);

        return back()->with('success', "{$supplier->name} restored.");
    }

    private function rules(): array
    {
        return [
            'name' => 'required|string|max:150',
            'city' => 'nullable|string|max:100',
            'contact_person' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email|max:150',
            'lead_time_days' => 'nullable|integer|min:0|max:60',
            'notes' => 'nullable|string|max:255',
        ];
    }
}
