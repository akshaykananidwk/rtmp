<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Domain\Settings\SettingsService;

/** Streaming file encryption with libsodium secretstream (XChaCha20-Poly1305). */
class BackupEncryptor
{
    private const CHUNK = 1024 * 1024;

    public function key(): string
    {
        $stored = app(SettingsService::class)->get('backups', 'encryption_key');
        if ($stored) {
            return base64_decode($stored, true) ?: $this->deriveFromAppKey();
        }

        return $this->deriveFromAppKey();
    }

    private function deriveFromAppKey(): string
    {
        $appKey = (string) config('app.key');
        if (str_starts_with($appKey, 'base64:')) {
            $appKey = base64_decode(substr($appKey, 7));
        }

        return sodium_crypto_generichash('ak-backup-key', $appKey, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }

    public function encrypt(string $in, string $out): void
    {
        $key = $this->key();
        $fi = fopen($in, 'rb');
        $fo = fopen($out, 'wb');
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($fo, $header);
        while (! feof($fi)) {
            $chunk = fread($fi, self::CHUNK);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $tag = feof($fi) ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            fwrite($fo, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag));
        }
        fclose($fi);
        fclose($fo);
    }

    public function decrypt(string $in, string $out): void
    {
        $key = $this->key();
        $fi = fopen($in, 'rb');
        $fo = fopen($out, 'wb');
        $header = fread($fi, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
        $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
        while (! feof($fi)) {
            $chunk = fread($fi, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $res = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $chunk);
            if ($res === false) {
                fclose($fi);
                fclose($fo);
                @unlink($out);
                throw new \RuntimeException('Backup decryption failed (wrong key or corrupted file)');
            }
            fwrite($fo, $res[0]);
        }
        fclose($fi);
        fclose($fo);
    }
}
