<?php
namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Models\Shift;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everyone's activity, in the app's own words: each action a page reports as done (its green success
 * message, or its amber warning when there's something to note) goes in the Activity Log with who did
 * it, on which shift, and from where. The ones an owner should look at go under "Needs a look".
 */
class RecordActivity
{
    // Always worth a look. A controller marks one more with the request attribute activity.needs_review,
    // like a shift that ends with a cash difference.
    private const NEEDS_A_LOOK = [
        'sales.void', 'returns.store',
        'inventory.mark-damaged', 'stock.loss', 'stock.write-off-board', 'stock.count', 'physical-counts.store',
        'users.destroy', 'users.two-factor.reset',
        'reports.sheet-link',   // sales figures readable by anyone holding the link
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only a message flashed by this request: one still showing from the request before was logged then.
        $flashed = $request->hasSession() ? $request->session()->get('_flash.new', []) : [];
        $message = collect(['success', 'warning'])->first(fn ($key) => in_array($key, $flashed, true));
        if ($message && $request->user() && ! $request->isMethodSafe()) {
            $route = $request->route();
            $shift = $route?->parameter('shift');   // a shift being ended is no longer open: say which it was
            ActivityLog::record(
                $request->user()->id,
                $route?->getName() ?? $request->path(),
                (string) $request->session()->get($message),
                in_array($route?->getName(), self::NEEDS_A_LOOK, true) || $request->attributes->get('activity.needs_review', false),
                $shift instanceof Shift ? $shift->id : null,
            );
        }

        return $response;
    }
}
