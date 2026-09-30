<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Shift extends Model
{
    protected $fillable = [
        'user_id', 'opened_at', 'closed_at', 'expected_sales',
        'actual_cash', 'variance', 'status',
    ];

    protected $casts = [
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
