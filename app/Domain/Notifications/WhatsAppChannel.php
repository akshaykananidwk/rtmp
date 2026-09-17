<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Domain\Settings\SettingsService;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Optional WhatsApp notifications through the official Meta WhatsApp Cloud API. */
class WhatsAppChannel
{
    public function __construct(private readonly SettingsService $settings) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! method_exists($notification, 'toWhatsApp')) {
            return;
        }

        $phoneId = $this->settings->get('notifications', 'phone_number_id', config('akstream.whatsapp.phone_number_id'));
        $token = $this->settings->get('notifications', 'access_token', config('akstream.whatsapp.access_token'));
        $to = $notifiable->phone ?? $this->settings->get('notifications', 'admin_number', config('akstream.whatsapp.admin_number'));

        if (! $phoneId || ! $token || ! $to) {
            return;
        }

        try {
            Http::withToken($token)->timeout(10)->post("https://graph.facebook.com/v21.0/{$phoneId}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => preg_replace('/\D+/', '', (string) $to),
                'type' => 'text',
                'text' => ['body' => mb_substr($notification->toWhatsApp($notifiable), 0, 4000)],
            ]);
        } catch (\Throwable $e) {
            Log::warning('WhatsApp notification failed: '.$e->getMessage());
        }
    }
}
