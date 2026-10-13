<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    protected $fillable = [
        'invoice_number', 'customer_id', 'user_id', 'subtotal', 'discount_amount',
        'tax_amount', 'total_amount', 'amount_paid', 'change_amount',
        'payment_method', 'status', 'notes', 'archived_at', 'archived_from',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function customer() { return $this->belongsTo(Customer::class); }
    public function user() { return $this->belongsTo(User::class)->withTrashed(); }
    public function items() { return $this->hasMany(SaleItem::class); }

    // Lines sold while out of stock that are still waiting for it (BackorderService).
    public function itemsOnOrder()
    {
        return $this->hasMany(SaleItem::class)->whereNotNull('backordered_at')->whereNull('backorder_filled_at');
    }

    // INV-20261011-0001: the day's next number. Today's numbers stay locked until the sale is saved,
    // so two cashiers ringing up at the same moment can't both get the same one (which made one
    // sale fail). Call it inside the transaction that saves the sale.
    public static function generateInvoiceNumber(): string
    {
        $prefix = 'INV-' . now()->format('Ymd') . '-';
        $last = static::where('invoice_number', 'like', $prefix . '%')
            ->orderByRaw('LENGTH(invoice_number) DESC, invoice_number DESC')
            ->lockForUpdate()->value('invoice_number');
        $next = $last ? (int) substr($last, strlen($prefix)) + 1 : 1;

        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }

    public function getBalanceDueAttribute(): float
    {
        return max($this->total_amount - $this->amount_paid, 0);
    }
}
