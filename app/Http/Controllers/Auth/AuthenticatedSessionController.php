<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

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

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        // The login page starts under the brand screen this page faded in, and fades it away.
        $request->session()->put('auth_splash', 'out');

        return redirect('/');
    }
}
