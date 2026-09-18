<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class VerifyEmailNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $brand = config('akstream.brand.name', 'AK COMPUTER');

        return (new MailMessage)
            ->subject('Confirm your e-mail for '.$brand)
            ->greeting('Hello '.$notifiable->name.',')
            ->line('Confirm this address so we can reach you about your streams.')
            ->action('Confirm e-mail', $this->url)
            ->line('The link works for 3 days. If you did not create an account, ignore this message.');
    }
}
