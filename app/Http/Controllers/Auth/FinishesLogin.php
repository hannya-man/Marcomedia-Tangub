<?php

namespace App\Http\Controllers\Auth;

use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

// The end of logging in, after the password (AuthenticatedSessionController) or, with two-step
// verification, after the code (TwoFactorChallengeController): the user is logged in by now.
trait FinishesLogin
{
    protected function finishLogin(Request $request): RedirectResponse
    {
        $request->session()->regenerate();

        // The first page shown starts under the brand screen the login page faded in, and fades it
        // away (partials/auth-splash). Taken by the first page drawn, after the redirects.
        $request->session()->put('auth_splash', 'in');

        // Back to the page asked for before logging in, if this role can open it. Else the role's
        // own start page: a cashier is never sent to an owner-only page just to be bounced from it.
        $intended = $request->session()->pull('url.intended');

        return redirect()->to($intended && $this->canOpen($request, $intended) ? $intended : RouteServiceProvider::HOME);
    }

    // Whether the logged-in user's role gets past the page's role:... check (EnsureRole).
    private function canOpen(Request $request, string $url): bool
    {
        $root = url('/');
        if ($url !== $root && ! str_starts_with($url, $root . '/') && ! str_starts_with($url, $root . '?')) {
            return false;
        }

        try {
            $route = app('router')->getRoutes()->match(Request::create(substr($url, strlen($root)) ?: '/'));
        } catch (\Throwable $e) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, 'role:')
                && ! in_array($request->user()->role, explode(',', substr($middleware, 5)), true)) {
                return false;
            }
        }

        return true;
    }
}
