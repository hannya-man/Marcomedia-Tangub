<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Throwable;

// One thing someone did. App\Http\Middleware\RecordActivity writes most of them; logging in and out
// are written by App\Providers\EventServiceProvider. Never edited afterwards.
class ActivityLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['user_id', 'shift_id', 'action', 'description', 'needs_review', 'ip_address'];

    protected $casts = ['needs_review' => 'boolean'];

    public function user() { return $this->belongsTo(User::class)->withTrashed(); }

    // By the time this runs the action itself is saved, so a log that can't be written is reported
    // instead of turning a finished action into an error page.
    public static function record(?int $userId, string $action, string $description, bool $needsReview = false, ?int $shiftId = null): void
    {
        try {
            static::create([
                'user_id' => $userId,
                'shift_id' => $shiftId ?? ($userId ? Shift::openFor($userId)?->id : null),
                'action' => $action,
                'description' => $description,
                'needs_review' => $needsReview,
                'ip_address' => request()?->ip(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
