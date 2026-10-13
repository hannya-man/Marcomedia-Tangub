<?php

namespace App\Http\Controllers\Concerns;

use App\Services\Auth\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

// Something that must be confirmed with the code from the logged-in user's authenticator app
// (deleting an account, changing two-step verification). A wrong code comes back as an error on
// $field, and guessing is slowed to 5 tries a minute.
trait ConfirmsWithTwoFactor
{
    protected function confirmWithTwoFactor(Request $request, string $field = 'code', string $bag = 'default'): void
    {
        $user = $request->user();
        $key = 'two-factor-confirm:' . $user->id;

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                $field => 'Too many wrong codes. Try again in ' . RateLimiter::availableIn($key) . ' seconds.',
            ])->errorBag($bag);
        }

        if (! app(TwoFactor::class)->verify($user, $request->input($field))) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([$field => $user->hasTwoFactor()
                ? "That code didn't match. Type the 6 digits your authenticator app shows now."
                : 'Turn on two-step verification first: Profile, Two-step verification.',
            ])->errorBag($bag);
        }

        RateLimiter::clear($key);
    }
}
