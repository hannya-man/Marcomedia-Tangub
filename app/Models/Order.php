<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    protected $fillable = [
        'order_number', 'customer_name', 'items_summary', 'item_count',
        'total_amount', 'status', 'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public static function generateOrderNumber(): string
    {
        $count = static::count() + 1;
        return 'ORD-' . str_pad($count, 4, '0', STR_PAD_LEFT);
    }
}
