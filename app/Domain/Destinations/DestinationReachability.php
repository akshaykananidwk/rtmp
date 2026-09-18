<?php

declare(strict_types=1);

namespace App\Domain\Destinations;

/**
 * Can this server actually reach a destination, and if not, at which layer does it stop?
 *
 * FFmpeg reports every one of these the same way — "Operation not permitted" or
 * "Input/output error" — which tells an operator nothing. Checking DNS, TCP and TLS
 * separately turns that into a decision: the server cannot get out, or it can and the
 * platform refused the stream key.
 */
class DestinationReachability
{
    public const TIMEOUT = 6;

    /**
     * @return array{ok:bool, stage:string, message:string, host:?string, port:?int, tls:bool}
     */
    public function check(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $tls = $scheme === 'rtmps';
        $port = (int) (parse_url($url, PHP_URL_PORT) ?: ($tls ? 443 : 1935));

        if (! $host) {
            return $this->result(false, 'url', 'The destination URL has no hostname.', null, null, $tls);
        }

        if (! $this->resolves($host)) {
            return $this->result(false, 'dns', 'The hostname '.$host.' does not resolve from this server — check DNS.', $host, $port, $tls);
        }

        $error = $this->connect($host, $port, false);
        if ($error !== null) {
            return $this->result(false, 'tcp',
                'This server cannot open a connection to '.$host.' on port '.$port.' ('.$error.'). '
                .'That is almost always an outbound firewall: allow outgoing TCP to port '.$port.'.',
                $host, $port, $tls);
        }

        if ($tls) {
            $error = $this->connect($host, $port, true);
            if ($error !== null) {
                return $this->result(false, 'tls',
                    'Port '.$port.' on '.$host.' is reachable but the TLS handshake failed ('.$error.'). '
                    .'Check the server clock and its CA certificates.',
                    $host, $port, $tls);
            }
        }

        // Everything below RTMP is fine, so a failure now is the platform's answer, not ours.
        return $this->result(true, 'reachable',
            $host.':'.$port.' is reachable'.($tls ? ' over TLS' : '').'. If publishing still fails, the platform is rejecting the stream key — it is usually expired or already used.',
            $host, $port, $tls);
    }

    private function resolves(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }

        return @gethostbyname($host) !== $host || @dns_get_record($host, DNS_A | DNS_AAAA) !== [];
    }

    /** @return string|null the error, or null when the connection succeeded */
    private function connect(string $host, int $port, bool $tls): ?string
    {
        $target = ($tls ? 'ssl://' : 'tcp://').$host.':'.$port;
        $context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'SNI_enabled' => true]]);

        try {
            $socket = @stream_socket_client($target, $code, $message, self::TIMEOUT, STREAM_CLIENT_CONNECT, $context);
        } catch (\Throwable $e) {
            return trim($e->getMessage()) ?: 'connection failed';
        }

        if ($socket === false) {
            return trim((string) $message) ?: 'error '.$code;
        }

        fclose($socket);

        return null;
    }

    /** @return array{ok:bool, stage:string, message:string, host:?string, port:?int, tls:bool} */
    private function result(bool $ok, string $stage, string $message, ?string $host, ?int $port, bool $tls): array
    {
        return ['ok' => $ok, 'stage' => $stage, 'message' => $message, 'host' => $host, 'port' => $port, 'tls' => $tls];
    }
}
