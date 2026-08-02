<?php
namespace App\Http\Controllers;

use App\Models\Material;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    public function index()
    {
        // Eager-load the variants (and their parent product) each material
        // is used by, so the connection between a material and the finished
        // sizes it makes is visible right on this page, not just implied.
        $materials = Material::with('variants.product')
            ->whereNull('archived_at')
            ->withCount('variants')
            ->orderBy('name')->get();
        $archivedCount = Material::whereNotNull('archived_at')->count();

        return view('inventory.materials', compact('materials', 'archivedCount'));
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'unit' => 'required|string|max:20',
            'stock_quantity' => 'required|numeric|min:0',
            'low_stock_threshold' => 'nullable|numeric|min:0',
            'cost_per_unit' => 'nullable|numeric|min:0',
        ]);

        Material::create($validated);

        return redirect()->route('inventory.materials')->with('success', 'Material added.');
    }

    // Restocking a material — e.g. "received a new 50-meter roll of fabric."
    public function adjustStock(Request $request, Material $material)
    {
        $validated = $request->validate([
            'quantity' => 'required|numeric|min:0.01',
        ]);

        $material->increment('stock_quantity', $validated['quantity']);

        return back()->with('success', "{$material->name} restocked by {$validated['quantity']} {$material->unit}.");
    }

    // "Delete" archives instead of removing the record — a material that's
    // still linked to sizes shouldn't just vanish and break that link's
    // history, and this matches how Products/Appointments/Sales all handle
    // deletion in this app.
    public function destroy(Material $material)
    {
        $material->update(['archived_at' => now()]);
        return back()->with('success', "'{$material->name}' archived.");
    }

    public function restore(Material $material)
    {
        $material->update(['archived_at' => null]);
        return back()->with('success', "'{$material->name}' restored.");
    }

    public function archive()
    {
        $archivedMaterials = Material::whereNotNull('archived_at')->orderByDesc('archived_at')->get();
        return view('inventory.materials-archive', compact('archivedMaterials'));
    }
}
