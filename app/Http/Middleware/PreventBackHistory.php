<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Stops the browser from serving a cached (back-button) copy of an
 * authenticated page after the user has logged out. Without this, hitting
 * "back" after logout can show the last-rendered dashboard/POS/etc. from
 * the browser's cache even though the session is gone — the page looks
 * accessible until you try to interact with it, which is confusing.
 */
class PreventBackHistory
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');

        return $response;
    }
}
