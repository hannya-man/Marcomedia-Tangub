<?php
namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The phone with the authenticator app is lost: after the right password, a one-time link to sign in
 * without the code. It turns two-step verification off so it can be set up on a new phone.
 */
class TwoFactorRecoveryLink extends Notification
{
    public function __construct(private string $url, private int $minutes)
    {
    }

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your sign-in link for Marcomedia POS')
            ->greeting("Hi {$notifiable->name},")
            ->line('Someone logged in to Marcomedia POS with your password and said the phone with your authenticator app is lost.')
            ->line('This button signs you in without the 6-digit code and turns off two-step verification, so you can set it up on your new phone.')
            ->action('Sign in to Marcomedia POS', $this->url)
            ->line("It works once, for {$this->minutes} minutes.")
            ->line("If this wasn't you, don't press it: change your password, or tell the owner.");
    }
}
