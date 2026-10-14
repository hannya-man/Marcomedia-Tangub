<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\TwoFactorRecoveryLink;
use App\Notifications\TwoFactorTurnedOff;
use App\Services\Auth\TwoFactor;
use App\Support\EmailRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * A lost phone, and no recovery codes: after the right password (the code page), a link by email
 * that logs in without the code and turns two-step verification off, so it can be set up on the new
 * phone. Getting in this way takes the password and the email inbox; the inbox alone isn't enough
 * (a forgotten password asks for the code, NewPasswordController).
 */
class TwoFactorEmailRecoveryController extends Controller
{
    private const MINUTES = 30;

    public function send(Request $request): RedirectResponse
    {
        $user = TwoFactorChallengeController::pendingUser($request);
        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'That took a while, so please log in again.']);
        }
        if (! EmailRecovery::available()) {
            return back();
        }
        if (! $user->hasVerifiedEmail()) {
            return back()->withErrors(['email_link' => "Your email was never confirmed, so a link can't be sent to it. Ask the owner to turn off two-step verification for you in User Management."]);
        }

        $key = 'two-factor-email:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            return back()->withErrors(['email_link' => 'A link was just sent. You can ask for another in ' . RateLimiter::availableIn($key) . ' seconds.']);
        }

        $url = URL::temporarySignedRoute('two-factor.recover', now()->addMinutes(self::MINUTES), [
            'user' => $user->id,
            'check' => self::fingerprint($user),
        ]);
        try {
            $user->notify(new TwoFactorRecoveryLink($url, self::MINUTES));
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['email_link' => "The email couldn't be sent right now. Try again in a few minutes."]);
        }
        RateLimiter::hit($key, 60);

        return back()->with('email_link_sent', 'Sent to ' . EmailRecovery::mask($user->email) . '. Open the email and press the button in it. Check Spam too.');
    }

    public function recover(Request $request, string $user, TwoFactor $twoFactor): RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/')->with('error', 'You are already logged in. To use the emailed link, log out first and then open it again.');
        }

        $account = User::find($user);
        // The link stops working once used (two-step verification is off, so the fingerprint
        // changes), when it's 30 minutes old, or if two-step verification was set up again.
        if (! $request->hasValidSignature() || ! $account || ! $account->hasTwoFactor()
            || ! hash_equals(self::fingerprint($account), (string) $request->query('check'))) {
            return redirect()->route('login')->withErrors(['email' => 'That sign-in link has expired or was already used. Log in again to ask for a new one.']);
        }

        $twoFactor->disable($account);
        $request->session()->forget(['login.id', 'login.remember', 'login.at']);
        Auth::login($account);
        $request->session()->regenerate();

        try {
            $account->notify(new TwoFactorTurnedOff);
        } catch (Throwable $e) {
            report($e);
        }

        return redirect()->route('two-factor.show')->with('success', "You're logged in, and two-step verification is off. Set it up on your new phone below.");
    }

    // Ties the link to this account's current two-step setup, without putting anything secret in it.
    private static function fingerprint(User $user): string
    {
        return hash_hmac('sha256', $user->id . '|' . $user->two_factor_secret, config('app.key'));
    }
}
