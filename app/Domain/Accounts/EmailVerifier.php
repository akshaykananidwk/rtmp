<?php

declare(strict_types=1);

namespace App\Domain\Accounts;

use App\Domain\Settings\SettingsService;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * Confirms that the address on an account can actually receive mail.
 *
 * Mail is often not configured on a self-hosted box, so sending must never break signing
 * up, and confirmation only blocks streaming when the operator explicitly turns that on.
 */
class EmailVerifier
{
    public const REQUIRED = 'require_email_verification';

    public const LINK_DAYS = 3;

    public function __construct(private readonly SettingsService $settings) {}

    public function link(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addDays(self::LINK_DAYS), [
            'user' => $user->id,
            // Ties the link to the address it was sent to: changing the e-mail kills it.
            'hash' => sha1($user->email),
        ]);
    }

    public function matches(User $user, string $hash): bool
    {
        return hash_equals(sha1($user->email), $hash);
    }

    /** @return bool whether the message actually went out */
    public function send(User $user): bool
    {
        if ($user->email_verified_at !== null) {
            return false;
        }

        try {
            $user->notify(new VerifyEmailNotification($this->link($user)));

            return true;
        } catch (\Throwable $e) {
            // An unconfigured SMTP server must not turn signing up into an error page.
            Log::warning('Could not send the verification e-mail', ['user' => $user->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    public function markVerified(User $user): void
    {
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }
    }

    public function isRequired(): bool
    {
        return (bool) $this->settings->get('registration', self::REQUIRED, false);
    }

    /** True when this user must confirm before they are allowed to stream. */
    public function blocks(?User $user): bool
    {
        return $this->isRequired() && $user !== null && $user->email_verified_at === null;
    }
}
