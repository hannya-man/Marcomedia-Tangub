<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\TwoFactor;
use App\Support\EmailRecovery;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\View\View;

/**
 * The page the emailed "Set a new password" link opens (PasswordResetLinkController). With two-step
 * verification on, it also asks for the code from the app (or a recovery code): the email inbox alone
 * never opens the account. Then it logs in.
 */
class NewPasswordController extends Controller
{
    use FinishesLogin;

    public function create(Request $request, string $token): View|RedirectResponse
    {
        $email = $request->query('email');
        $user = is_string($email) ? $this->linkOwner($email, $token) : null;
        if (! $user) {
            return $this->linkExpired();
        }

        return view('auth.reset-password', [
            'token' => $token,
            'email' => $user->email,
            'needsCode' => $user->hasTwoFactor(),
        ]);
    }

    public function store(Request $request, TwoFactor $twoFactor): RedirectResponse
    {
        $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = $this->linkOwner($request->input('email'), $request->input('token'));
        if (! $user) {
            return $this->linkExpired();
        }

        $useRecovery = false;
        if ($user->hasTwoFactor()) {
            $useRecovery = $request->boolean('use_recovery');
            $field = $useRecovery ? 'recovery_code' : 'code';
            // The same count as the code page's (TwoFactorChallengeController): wrong codes here
            // don't buy more guesses there.
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
        }

        $status = Password::reset(
            ['email' => $user->email] + $request->only('password', 'password_confirmation', 'token'),
            function (User $user) use ($request) {
                $user->forceFill([
                    'password' => Hash::make($request->input('password')),
                    'remember_token' => Str::random(60),
                ])->save();

                // Logged in anywhere else (maybe by whoever learned the old password): not any more.
                if (config('session.driver') === 'database') {
                    DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
                }

                event(new PasswordReset($user));
            }
        );
        if ($status !== Password::PASSWORD_RESET) {
            return $this->linkExpired();
        }

        Auth::login($user->fresh());
        $response = $this->finishLogin($request)->with('success', 'Your new password is saved.');
        if ($useRecovery) {
            $left = $twoFactor->recoveryCodesLeft($user);
            $response->with('error', "You used a recovery code: {$left} left. If your phone is lost, set up the app on your new one in Profile, Two-step verification.");
        }

        return $response;
    }

    // Whose link this is, while it still works: the right email, and a token not yet used or expired.
    private function linkOwner(string $email, string $token): ?User
    {
        $user = User::where('email', $email)->first();

        return $user && Password::broker()->tokenExists($user, $token) ? $user : null;
    }

    private function linkExpired(): RedirectResponse
    {
        // Back to asking for a new link (or to log in, if email was turned off since).
        return redirect()->route(EmailRecovery::available() ? 'password.request' : 'login')
            ->withErrors(['email' => 'That link has expired or was already used. Ask for a new one.']);
    }
}
