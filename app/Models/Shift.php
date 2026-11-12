<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = [
        'user_id', 'opened_at', 'opening_cash', 'closed_at', 'expected_sales',
        'actual_cash', 'variance', 'status',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class)->withTrashed(); }
    public function sales() { return $this->hasMany(Sale::class); }
    public function payments() { return $this->hasMany(SalePayment::class); }

    public static function openFor(int $userId): ?self
    {
        return static::where('user_id', $userId)->where('status', 'open')->latest('id')->first();
    }

    // What the drawer should hold: the cash it started with, and every cash payment taken in this
    // shift (paid in full, down payments, and balances paid later). GCash never touches the drawer.
    public function expectedCash(): float
    {
        return round((float) $this->opening_cash + (float) $this->payments()->where('method', 'cash')->sum('amount'), 2);
    }
}
