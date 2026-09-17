<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Domain\Notifications\WhatsAppChannel;
use App\Domain\Settings\SettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** One notification class for all system events: email + database + optional WhatsApp. */
class SystemNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $message,
        public readonly string $level = 'info',
        public readonly ?string $url = null,
        public readonly array $meta = [],
    ) {}

    public function via(object $notifiable): array
    {
        $settings = app(SettingsService::class);
        $channels = ['database'];

        if ($settings->bool('notifications', 'email_enabled', true) && ! empty($notifiable->email)) {
            $channels[] = 'mail';
        }
        if ($settings->bool('notifications', 'whatsapp_enabled', false) && $this->level !== 'info') {
            $channels[] = WhatsAppChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('['.strtoupper($this->level).'] '.$this->title)
            ->greeting($this->title)
            ->line($this->message);

        if ($this->url) {
            $mail->action('Open dashboard', $this->url);
        }

        return $mail->line('— '.config('akstream.brand.name').' · '.config('akstream.brand.product'));
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => $this->type, 'title' => $this->title, 'message' => $this->message, 'level' => $this->level, 'url' => $this->url, 'meta' => $this->meta];
    }

    public function toWhatsApp(object $notifiable): string
    {
        return '*'.$this->title."*\n".$this->message;
    }
}
