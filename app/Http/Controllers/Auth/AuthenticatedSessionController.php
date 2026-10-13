<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    use FinishesLogin;

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
        $user = $request->authenticate();

        // Two-step verification: the password was right, and now the code from the app
        // (TwoFactorChallengeController). Not logged in until then.
        if ($user->hasTwoFactor()) {
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', $request->boolean('remember'));
            $request->session()->put('login.at', now()->timestamp);
            // The code page starts under the brand screen the login page faded in.
            $request->session()->put('auth_splash', 'out');

            return redirect()->route('two-factor.login');
        }

        Auth::login($user, $request->boolean('remember'));

        return $this->finishLogin($request);
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
