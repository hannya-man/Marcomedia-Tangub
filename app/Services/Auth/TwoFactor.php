<?php
namespace App\Services\Auth;

use App\Models\User;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-step verification with an authenticator app (Google Authenticator, Microsoft Authenticator,
 * Authy, ...): after the password, a 6-digit code from the app, which changes every 30 seconds
 * (the standard TOTP codes). The app is set up by scanning a QR code, or by typing the setup key.
 * Owners must use it (EnsureOwnerTwoFactor); owners also confirm deleting an account with a code.
 *
 * Each code works once: a code seen over someone's shoulder can't be used again.
 */
class TwoFactor
{
    // Codes from the 30-second step before and after the current one count too: phone clocks drift.
    private const WINDOW = 1;

    // Recovery codes: no 0/o, 1/l/i, so they read back without mistakes.
    private const RECOVERY_ALPHABET = '23456789abcdefghjkmnpqrstuvwxyz';

    public function __construct(private Google2FA $engine)
    {
    }

    // Starts setup, or starts it again: a new secret, used only once a code from the app confirms it.
    public function begin(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => $this->engine->generateSecretKey(32),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    // A code from the app proves it was set up right: from now on logging in asks for one.
    // Returns the recovery codes (shown once), or null if the code is wrong.
    public function confirm(User $user, ?string $code): ?array
    {
        if (! $user->two_factor_secret || $user->hasTwoFactor() || ! $this->checkCode($user, $code)) {
            return null;
        }

        $codes = $this->makeRecoveryCodes();
        $user->forceFill(['two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }

    // The code from the app, to log in or to confirm something (deleting an account).
    public function verify(User $user, ?string $code): bool
    {
        return $user->hasTwoFactor() && $this->checkCode($user, $code);
    }

    // A recovery code instead of the app (a lost phone). Each one works once.
    public function useRecoveryCode(User $user, ?string $code): bool
    {
        $given = $this->normalizeRecoveryCode($code);
        if (! $user->hasTwoFactor() || strlen($given) !== 10) {
            return false;
        }

        $left = collect($user->two_factor_recovery_codes ?? []);
        $match = $left->search(fn ($c) => hash_equals($this->normalizeRecoveryCode($c), $given));
        if ($match === false) {
            return false;
        }

        $user->forceFill(['two_factor_recovery_codes' => $left->forget($match)->values()->all()])->save();

        return true;
    }

    // New recovery codes; the old ones stop working. Shown once.
    public function renewRecoveryCodes(User $user): array
    {
        $codes = $this->makeRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $codes])->save();

        return $codes;
    }

    public function recoveryCodesLeft(User $user): int
    {
        return count($user->two_factor_recovery_codes ?? []);
    }

    // What the app scans: an otpauth:// link, as a QR code (an SVG data URI for an <img>).
    public function qrCode(User $user): string
    {
        $link = $this->engine->getQRCodeUrl('Marcomedia POS', $user->email, $user->two_factor_secret);

        return (new QRCode(new QROptions([
            'outputType' => QRCode::OUTPUT_MARKUP_SVG,
            'eccLevel' => QRCode::ECC_M,
            'addQuietzone' => true,
            'imageBase64' => true,
        ])))->render($link);
    }

    // The same secret, for typing into the app: in groups of four, easier to copy by hand.
    public function setupKey(User $user): string
    {
        return trim(chunk_split((string) $user->two_factor_secret, 4, ' '));
    }

    // The 6-digit code the app shows now (tests, and a form that needs one).
    public function currentCode(string $secret): string
    {
        return $this->engine->getCurrentOtp($secret);
    }

    private function checkCode(User $user, ?string $code): bool
    {
        $code = preg_replace('/\D/', '', (string) $code);
        if (strlen($code) !== 6) {
            return false;
        }

        // Only codes newer than the last one used. (With no last step given, the library answers
        // true instead of the step, so it starts from 0.)
        $step = $this->engine->verifyKeyNewer($user->two_factor_secret, $code, (int) ($user->two_factor_last_step ?? 0), self::WINDOW);
        if ($step === false) {
            return false;
        }

        $user->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }

    private function makeRecoveryCodes(): array
    {
        $alphabet = self::RECOVERY_ALPHABET;
        $pick = fn () => $alphabet[random_int(0, strlen($alphabet) - 1)];

        return collect(range(1, 8))->map(function () use ($pick) {
            $chars = implode('', array_map(fn () => $pick(), range(1, 10)));

            return substr($chars, 0, 5) . '-' . substr($chars, 5);
        })->all();
    }

    private function normalizeRecoveryCode(?string $code): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower((string) $code));
    }
}
