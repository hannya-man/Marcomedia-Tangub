<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ChatMessage extends Model
{
    protected $fillable = ['user_id', 'message', 'reactions'];

    protected $casts = [
        'reactions' => 'array',
    ];

    public function user() { return $this->belongsTo(User::class); }
}
