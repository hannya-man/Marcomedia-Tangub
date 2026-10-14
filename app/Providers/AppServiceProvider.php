<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        // Every paginated list (Products, Sales) uses shadcn-style page links.
        Paginator::defaultView('pagination.shadcn');

        // The account emails, in the app's own plain words (EmailRecovery).
        ResetPassword::toMailUsing(function ($notifiable, string $token) {
            $url = route('password.reset', ['token' => $token, 'email' => $notifiable->getEmailForPasswordReset()]);
            $minutes = config('auth.passwords.users.expire');

            return (new MailMessage)
                ->subject('Set a new password for Marcomedia POS')
                ->greeting("Hi {$notifiable->name},")
                ->line('Someone asked to set a new password for your Marcomedia POS account.')
                ->action('Set a new password', $url)
                ->line("The link works once, for {$minutes} minutes.")
                ->line("If you didn't ask for this, ignore this email: your password stays the same.");
        });

        VerifyEmail::toMailUsing(function ($notifiable, string $url) {
            return (new MailMessage)
                ->subject('Confirm your email for Marcomedia POS')
                ->greeting("Hi {$notifiable->name},")
                ->line('Press the button to confirm this is your email. Then, if you ever forget your password or lose your phone, Marcomedia POS can email you a way back in.')
                ->action('Confirm my email', $url)
                ->line('The link works for 60 minutes.')
                ->line("If you didn't ask for this, ignore this email.");
        });
    }
}
