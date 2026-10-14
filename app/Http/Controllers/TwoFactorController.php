<?php
namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\ConfirmsWithTwoFactor;
use App\Services\Auth\TwoFactor;
use App\Support\EmailRecovery;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The logged-in user's own two-step verification: set it up (scan the QR code or type the setup
 * key into an authenticator app, then type a code to confirm), make new recovery codes, move it to
 * a new phone, or turn it off (cashiers only: owners must keep it, see EnsureOwnerTwoFactor).
 */
class TwoFactorController extends Controller
{
    use ConfirmsWithTwoFactor;

    public function __construct(private TwoFactor $twoFactor)
    {
    }

    public function show(Request $request)
    {
        $user = $request->user();
        // Started and waiting for the first code from the app.
        $settingUp = $user->two_factor_secret !== null && ! $user->hasTwoFactor();

        return view('profile.two-factor', [
            'user' => $user,
            'settingUp' => $settingUp,
            'qrCode' => $settingUp ? $this->twoFactor->qrCode($user) : null,
            'setupKey' => $settingUp ? $this->twoFactor->setupKey($user) : null,
            'codesLeft' => $user->hasTwoFactor() ? $this->twoFactor->recoveryCodesLeft($user) : 0,
            // Just made: shown this once.
            'newCodes' => session('recovery_codes'),
            // A lost phone can also be fixed by email, once the email is confirmed.
            'suggestEmail' => EmailRecovery::available() && ! $user->hasVerifiedEmail(),
        ]);
    }

    // Start setup. Already on (a new phone): the code from the current phone first.
    public function begin(Request $request)
    {
        if ($request->user()->hasTwoFactor()) {
            $this->confirmWithTwoFactor($request);
        }
        $this->twoFactor->begin($request->user());

        return redirect()->route('two-factor.show');
    }

    // The first code from the app: it's set up right, so it's on from now.
    public function confirm(Request $request)
    {
        $user = $request->user();
        $key = 'two-factor-setup:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Too many wrong codes. Try again in ' . RateLimiter::availableIn($key) . ' seconds.']);
        }

        $codes = $this->twoFactor->confirm($user, $request->input('code'));
        if ($codes === null) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['code' => "That code didn't match. Check that the app shows Marcomedia POS, and type the 6 digits it shows now."]);
        }
        RateLimiter::clear($key);

        return redirect()->route('two-factor.show')
            ->with('success', 'Two-step verification is on. From now on, logging in asks for the code from your app.')
            ->with('recovery_codes', $codes);
    }

    // Stop a setup before its first code: back to off. (An owner is sent back to set it up.)
    public function cancel(Request $request)
    {
        $user = $request->user();
        if (! $user->hasTwoFactor()) {
            $this->twoFactor->disable($user);
        }

        return redirect()->route('profile.edit');
    }

    public function renewCodes(Request $request)
    {
        $this->confirmWithTwoFactor($request);
        $codes = $this->twoFactor->renewRecoveryCodes($request->user());

        return redirect()->route('two-factor.show')
            ->with('success', 'New recovery codes made. The old ones no longer work.')
            ->with('recovery_codes', $codes);
    }

    public function disable(Request $request)
    {
        if ($request->user()->isAdmin()) {
            return back()->with('error', 'Owner accounts must keep two-step verification on.');
        }
        $this->confirmWithTwoFactor($request);
        $this->twoFactor->disable($request->user());

        return redirect()->route('two-factor.show')->with('success', 'Two-step verification is off. Logging in asks for your password only.');
    }
}
