<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ProductVariant extends Model
{
    protected $fillable = [
        'product_id', 'variant_name', 'sku', 'attributes', 'price',
        'cost_price', 'stock_quantity', 'low_stock_threshold',
        'reorder_point', 'status',
    ];

    protected $casts = [
        'attributes' => 'array',
        'price' => 'decimal:2',
        'cost_price' => 'decimal:2',
    ];

    public function product() { return $this->belongsTo(Product::class); }
    public function movements() { return $this->hasMany(InventoryMovement::class); }

    // A variant can consume MULTIPLE materials per unit — e.g. a
    // sublimation shirt uses fabric AND ink, at different rates each.
    public function materials()
    {
        return $this->belongsToMany(Material::class, 'variant_materials')
            ->withPivot('quantity_per_unit')
            ->withTimestamps();
    }

    public function getEffectivePriceAttribute()
    {
        return $this->price ?? $this->product->base_price;
    }

    // Stock status: out / low / ok - this is what powers the dashboard alerts
    public function getStockStatusAttribute(): string
    {
        if ($this->stock_quantity <= 0) return 'out_of_stock';
        if ($this->stock_quantity <= $this->low_stock_threshold) return 'low_stock';
        return 'in_stock';
    }

    /**
     * The single place stock ever changes. Every deduction/addition goes
     * through here so there's always a logged movement behind every number.
     */
    public function adjustStock(int $signedQty, string $type, ?string $refType = null, ?int $refId = null, ?string $remarks = null, ?int $userId = null): void
    {
        $before = $this->stock_quantity;
        $after = $before + $signedQty;

        if ($after < 0) {
            throw new \RuntimeException("Not enough stock for {$this->variant_name} (have {$before}, need " . abs($signedQty) . ")");
        }

        $this->stock_quantity = $after;
        $this->save();

        $this->movements()->create([
            'type' => $type,
            'quantity' => $signedQty,
            'stock_before' => $before,
            'stock_after' => $after,
            'reference_type' => $refType,
            'reference_id' => $refId,
            'remarks' => $remarks,
            'user_id' => $userId,
            'created_at' => now(),
        ]);

        // Producing new finished stock (a "stock_in" / restock) consumes
        // EVERY material this variant is linked to — a sublimation shirt
        // draws down fabric AND ink at the same time, each at its own
        // rate. Selling a shirt does NOT touch materials — the fabric/ink
        // was already used when the shirt was produced, not when it's
        // sold off the shelf.
        if ($type === 'stock_in' && $signedQty > 0) {
            foreach ($this->materials as $material) {
                $material->decrement('stock_quantity', $signedQty * $material->pivot->quantity_per_unit);
            }
        }
    }
}
