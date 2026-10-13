<?php
namespace Tests\Feature\Concerns;

use App\Models\User;
use App\Services\Auth\TwoFactor;
use PragmaRX\Google2FA\Google2FA;

// Owners must use two-step verification (EnsureOwnerTwoFactor), so the tests' owners have it, set
// up the way the app does it: setup started, then confirmed with a code from the "phone".
trait UsesTwoFactor
{
    protected function withTwoFactor(User $user): User
    {
        $twoFactor = app(TwoFactor::class);
        $twoFactor->begin($user);
        $twoFactor->confirm($user, $twoFactor->currentCode($user->two_factor_secret));

        return $user;
    }

    // The code the phone shows now. Each code works once, so this is as if the last one used was a
    // minute ago.
    protected function codeFor(User $user): string
    {
        $engine = new Google2FA();
        $user->forceFill(['two_factor_last_step' => $engine->getTimestamp() - 2])->save();

        return $engine->getCurrentOtp($user->two_factor_secret);
    }
}
