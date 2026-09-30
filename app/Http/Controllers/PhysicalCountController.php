<?php
namespace App\Http\Controllers;

use App\Models\PhysicalCount;
use App\Models\ProductVariant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PhysicalCountController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'counts' => 'required|array',
            'counts.*.product_variant_id' => 'required|exists:product_variants,id',
            'counts.*.counted_quantity' => 'required|integer|min:0',
        ]);

        foreach ($validated['counts'] as $row) {
            $variant = ProductVariant::findOrFail($row['product_variant_id']);

            PhysicalCount::create([
                'product_variant_id' => $variant->id,
                'system_quantity' => $variant->sellable_quantity,
                'counted_quantity' => $row['counted_quantity'],
                'variance' => $row['counted_quantity'] - $variant->sellable_quantity,
                'counted_by' => Auth::id(),
                'counted_at' => now(),
            ]);
        }

        return back()->with('success', 'Physical count logged.');
    }
}
