<?php
namespace App\Http\Controllers;

use App\Models\InventoryBatch;
use App\Models\Material;
use Illuminate\Http\Request;

class InventoryBatchController extends Controller
{
    // Location is required on every receive — there's no default, so a
    // batch can never enter the system without one.
    public function store(Request $request)
    {
        $validated = $request->validate([
            'material_id' => 'required|exists:materials,id',
            'location' => 'required|in:store,warehouse',
            'quantity' => 'required|numeric|min:0.01',
            'cost_per_unit' => 'nullable|numeric|min:0',
        ]);

        $material = Material::findOrFail($validated['material_id']);

        $batch = InventoryBatch::receive(
            $material, $validated['quantity'], $validated['location'], $validated['cost_per_unit'] ?? null
        );

        return back()->with('success', "Batch {$batch->batch_number} received into {$validated['location']}.");
    }
}
