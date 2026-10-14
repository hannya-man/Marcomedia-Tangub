<?php
namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent after the emailed sign-in link turned two-step verification off: it shouldn't happen unnoticed. */
class TwoFactorTurnedOff extends Notification
{
    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Two-step verification was turned off')
            ->greeting("Hi {$notifiable->name},")
            ->line('You signed in to Marcomedia POS with the emailed link, so two-step verification is now off on your account.')
            ->line('Set it up on your new phone in Profile, Two-step verification. (Owners are asked to right away.)')
            ->line("If this wasn't you, tell the owner and change your password now.");
    }
}
