<?php
namespace App\Support;

/**
 * Account recovery by email ("Forgot your password?", and a sign-in link when the phone with the
 * authenticator app is lost) needs a mailer that really sends. Until Gmail (or another mailer) is set
 * up, the links stay hidden instead of failing. A link back in only goes to an email confirmed in Profile.
 */
class EmailRecovery
{
    public static function available(): bool
    {
        return match (config('mail.default')) {
            // Gmail: smtp.gmail.com with the account's app password.
            'smtp' => filled(config('mail.mailers.smtp.username')) && filled(config('mail.mailers.smtp.password')),
            // Written to the log or kept in memory: only on the PC and in tests.
            'log', 'array' => app()->environment('local', 'testing'),
            default => true,
        };
    }

    // "k***@gmail.com": enough to recognize, not to read off the screen.
    public static function mask(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($name, 0, 1) . '***@' . $domain;
    }
}
