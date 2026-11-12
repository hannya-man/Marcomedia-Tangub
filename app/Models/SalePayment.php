<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

// One payment on a sale, taken by App\Services\Sales\SalePayments. Never edited afterwards.
class SalePayment extends Model
{
    const UPDATED_AT = null;

    public const METHODS = ['cash' => 'Cash', 'gcash' => 'GCash'];

    protected $fillable = ['sale_id', 'amount', 'method', 'reference', 'note', 'received_by', 'shift_id'];

    public function sale() { return $this->belongsTo(Sale::class); }
    public function receivedBy() { return $this->belongsTo(User::class, 'received_by')->withTrashed(); }
    public function shift() { return $this->belongsTo(Shift::class); }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? ucfirst($this->method);
    }
}
