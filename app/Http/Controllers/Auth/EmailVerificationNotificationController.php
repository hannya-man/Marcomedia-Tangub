<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\EmailRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * "Send confirmation email" in Profile: confirming the account's email is what lets it get a link
 * back in (a forgotten password, a lost phone). The email's button: VerifyEmailController.
 */
class EmailVerificationNotificationController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return back();
        }
        if (! EmailRecovery::available()) {
            return back()->with('error', "Email isn't set up on this system yet, so nothing can be sent.");
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', "The email couldn't be sent right now. Check the address, then try again in a few minutes.");
        }

        return back()->with('status', 'verification-link-sent');
    }
}
