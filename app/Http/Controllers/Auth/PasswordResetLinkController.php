<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\EmailRecovery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;
use Throwable;

/**
 * "Forgot your password?": a link to set a new one, by email. Only to an address its owner confirmed
 * (Profile): an account's email can be wrong or someone else's, and the link opens the account.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): View|RedirectResponse
    {
        if (! EmailRecovery::available()) {
            return redirect()->route('login');
        }

        return view('auth.forgot-password');
    }

    // The answer is the same whatever was typed, so the page doesn't tell anyone which emails have
    // accounts. Throttled by the password broker too: one link a minute per account.
    public function store(Request $request): RedirectResponse
    {
        if (! EmailRecovery::available()) {
            return redirect()->route('login');
        }
        $request->validate(['email' => ['required', 'email']]);

        $user = User::where('email', $request->input('email'))->first();
        if ($user && $user->hasVerifiedEmail()) {
            try {
                Password::sendResetLink(['email' => $user->email]);
            } catch (Throwable $e) {
                report($e);

                return back()->withInput()->withErrors(['email' => "The email couldn't be sent right now. Try again in a few minutes."]);
            }
        }

        return back()->with('status', "If that email belongs to an account and was confirmed, a link to set a new password is on its way. Check your inbox, and Spam too.");
    }
}
