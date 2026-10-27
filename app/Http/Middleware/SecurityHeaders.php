<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Browser safety headers on every response:
 * - no other site may show these pages in a frame (a hidden frame could trick a click on Approve,
 *   Void or Delete account: clickjacking). No page here frames another one.
 * - files are taken as the type they're sent as, never guessed (nosniff).
 * - links to other sites get this site's name only, never a full address (a password-reset or
 *   sign-in link carries its token in the address).
 * And the PHP version isn't announced: it told anyone which known PHP bugs to try.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Content-Security-Policy', "frame-ancestors 'none'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        header_remove('X-Powered-By');

        return $response;
    }
}
