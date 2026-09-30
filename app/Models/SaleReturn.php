<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SaleReturn extends Model
{
    protected $table = 'returns'; // "Return" is a reserved PHP word, can't name the class that
    public $timestamps = false;
    protected $fillable = ['sale_item_id', 'quantity', 'condition', 'reason', 'processed_by', 'created_at'];
    protected $casts = ['created_at' => 'datetime'];

    public function saleItem() { return $this->belongsTo(SaleItem::class); }
    public function processedBy() { return $this->belongsTo(User::class, 'processed_by'); }
}
