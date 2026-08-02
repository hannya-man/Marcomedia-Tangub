<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureUserHasRole
{
    /**
     * Usage in routes: ->middleware('role:cashier') / ->middleware('role:photographer')
     * Admin always passes through, regardless of which roles are listed —
     * Admin can reach every area of the system.
     *
     * If someone lands on a page their role can't use (most commonly:
     * right after logging in, since everyone gets sent to /dashboard first),
     * we redirect them to wherever their role actually starts, rather than
     * showing a bare 403.
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user) {
            return redirect()->route('login');
        }

        if ($user->role === 'admin' || in_array($user->role, $roles)) {
            return $next($request);
        }

        return match ($user->role) {
            'cashier' => redirect()->route('pos.index'),
            'photographer' => redirect()->route('photographer.appointments.index'),
            default => abort(403),
        };
    }
}
