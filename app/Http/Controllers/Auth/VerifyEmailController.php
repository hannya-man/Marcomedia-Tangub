<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The "Confirm my email" button in the email sent from Profile (EmailVerificationNotificationController).
 * An old or wrong link says so in Profile, where a new one is a click away (not a bare error page).
 */
class VerifyEmailController extends Controller
{
    public function __invoke(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = $request->user();
        if (! $request->hasValidSignature()) {
            return redirect()->route('profile.edit')->with('error', 'That confirmation link has expired. Send a new one below.');
        }
        if (! hash_equals((string) $user->getKey(), $id) || ! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            return redirect()->route('profile.edit')->with('error', "That confirmation link is for another account, or for an email this account no longer uses.");
        }

        if (! $user->hasVerifiedEmail() && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect()->route('profile.edit')
            ->with('success', 'Your email is confirmed. If you forget your password or lose your phone, you can get a link sent to it.');
    }
}
