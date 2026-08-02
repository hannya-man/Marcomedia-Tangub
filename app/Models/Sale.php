<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'invoice_number', 'customer_id', 'user_id', 'subtotal', 'discount_amount',
        'tax_amount', 'total_amount', 'amount_paid', 'change_amount',
        'payment_method', 'status', 'notes', 'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function user() { return $this->belongsTo(User::class); }
    public function items() { return $this->hasMany(SaleItem::class); }

    public static function generateInvoiceNumber(): string
    {
        $today = now()->format('Ymd');
        $count = static::whereDate('created_at', now())->count() + 1;
        return "INV-{$today}-" . str_pad($count, 4, '0', STR_PAD_LEFT);
    }

    public function getBalanceDueAttribute(): float
    {
        return max($this->total_amount - $this->amount_paid, 0);
    }
}
