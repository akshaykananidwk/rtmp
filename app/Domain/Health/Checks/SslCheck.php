<?php

declare(strict_types=1);

namespace App\Domain\Health\Checks;

use App\Domain\Health\CheckResult;
use App\Domain\Health\HealthCheckInterface;

class SslCheck implements HealthCheckInterface
{
    public function name(): string
    {
        return 'ssl';
    }

    public function label(): string
    {
        return 'SSL';
    }

    public function critical(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $url = (string) config('app.url');
        $host = parse_url($url, PHP_URL_HOST);
        $details = ['url' => $url];

        if (! str_starts_with($url, 'https://')) {
            return app()->isProduction()
                ? CheckResult::warn($this->name(), 'APP_URL is not HTTPS', $details)
                : CheckResult::pass($this->name(), 'HTTPS not required outside production', $details);
        }
        if (! $host || in_array($host, ['localhost', '127.0.0.1'], true)) {
            return CheckResult::warn($this->name(), 'Cannot verify certificate for '.$host, $details);
        }

        try {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);
            $client = @stream_socket_client("ssl://$host:443", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
            if (! $client) {
                return CheckResult::fail($this->name(), "TLS handshake failed: $errstr", $details);
            }
            $params = stream_context_get_params($client);
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
            fclose($client);
            $expires = (int) ($cert['validTo_time_t'] ?? 0);
            $days = (int) floor(($expires - time()) / 86400);
            $details += ['issuer' => $cert['issuer']['O'] ?? null, 'expires_in_days' => $days, 'subject' => $cert['subject']['CN'] ?? null];
            if ($days < 0) {
                return CheckResult::fail($this->name(), 'Certificate expired', $details);
            }
            if ($days < 14) {
                return CheckResult::warn($this->name(), "Certificate expires in $days days", $details);
            }

            return CheckResult::pass($this->name(), "Valid, expires in $days days", $details);
        } catch (\Throwable $e) {
            return CheckResult::warn($this->name(), 'Could not verify: '.$e->getMessage(), $details);
        }
    }
}
