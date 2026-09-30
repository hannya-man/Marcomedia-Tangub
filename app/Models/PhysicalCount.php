<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PhysicalCount extends Model
{
    public $timestamps = false;
    protected $fillable = [
        'product_variant_id', 'system_quantity', 'counted_quantity',
        'variance', 'counted_by', 'counted_at',
    ];
    protected $casts = ['counted_at' => 'datetime'];

    public function variant() { return $this->belongsTo(ProductVariant::class, 'product_variant_id'); }
    public function countedBy() { return $this->belongsTo(User::class, 'counted_by'); }
}
