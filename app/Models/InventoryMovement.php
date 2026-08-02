<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class InventoryMovement extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'product_variant_id', 'type', 'quantity', 'stock_before', 'stock_after',
        'reference_type', 'reference_id', 'remarks', 'user_id', 'created_at',
    ];
    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function variant() { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    public function user() { return $this->belongsTo(User::class); }
}
