<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Logging in with two-step verification: after the right password (AuthenticatedSessionController),
 * the 6-digit code from the authenticator app, or a recovery code when the phone is lost.
 */
class TwoFactorChallengeController extends Controller
{
    use FinishesLogin;

    // The password step is good for this long; then it starts over.
    private const MINUTES = 10;

    public function create(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $user = $this->pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'That took a while, so please log in again.']);
        }

        $useRecovery = $request->boolean('use_recovery');
        $field = $useRecovery ? 'recovery_code' : 'code';
        $key = 'two-factor-login:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withInput(['use_recovery' => $useRecovery])
                ->withErrors([$field => 'Too many wrong codes. Try again in ' . RateLimiter::availableIn($key) . ' seconds.']);
        }

        $ok = $useRecovery
            ? $twoFactor->useRecoveryCode($user, $request->input('recovery_code'))
            : $twoFactor->verify($user, $request->input('code'));
        if (! $ok) {
            RateLimiter::hit($key, 60);

            return back()->withInput(['use_recovery' => $useRecovery])->withErrors([$field => $useRecovery
                ? "That recovery code didn't work. Each one works once; type it as it's written."
                : "That code didn't match. Type the 6 digits your authenticator app shows now for Marcomedia POS."]);
        }
        RateLimiter::clear($key);

        $remember = (bool) $request->session()->pull('login.remember', false);
        $request->session()->forget(['login.id', 'login.at']);
        Auth::login($user, $remember);

        $response = $this->finishLogin($request);
        if ($useRecovery) {
            $left = $twoFactor->recoveryCodesLeft($user);
            $response->with('error', "You logged in with a recovery code: {$left} left. If your phone is lost, set up the app on your new one in Profile, Two-step verification.");
        }

        return $response;
    }

    // Whose password was just right, if it was recently enough.
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('login.id');
        $at = (int) $request->session()->get('login.at');
        if (! $id || $at < now()->subMinutes(self::MINUTES)->timestamp) {
            $request->session()->forget(['login.id', 'login.remember', 'login.at']);

            return null;
        }

        return User::find($id);
    }
}
