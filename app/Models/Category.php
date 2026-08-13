<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Category extends Model
{
    protected $fillable = ['name', 'slug', 'archived_at'];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function products() { return $this->hasMany(Product::class); }
}
