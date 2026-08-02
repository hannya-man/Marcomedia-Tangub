<?php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class EnsureRole
{
    /**
     * Usage in routes: ->middleware('role:admin,cashier')
     * If the logged-in user's role isn't in the allowed list, they get
     * bounced to their own default page instead of a hard 403 — smoother
     * for a POS system where staff might click a stale bookmark.
     */
    public function handle(Request $request, Closure $next, ...$roles)
    {
        $user = $request->user();

        if (!$user || !in_array($user->role, $roles)) {
            return redirect()->route($this->defaultRouteFor($user?->role))
                ->with('error', "You don't have access to that page.");
        }

        return $next($request);
    }

    protected function defaultRouteFor(?string $role): string
    {
        return match ($role) {
            'admin' => 'dashboard',
            'cashier' => 'pos.index',
            default => 'login',
        };
    }
}
