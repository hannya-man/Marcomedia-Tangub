<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Owner (admin) accounts must use two-step verification: until it's set up, every page sends an
 * owner to the setup page (TwoFactorController). Cashiers can turn it on in their profile.
 * The setup page itself, chat and keep-alive sit outside this check (routes/web.php).
 */
class EnsureOwnerTwoFactor
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $user->isAdmin() && ! $user->hasTwoFactor()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Set up two-step verification first.'], 403)
                : redirect()->route('two-factor.show');
        }

        return $next($request);
    }
}
