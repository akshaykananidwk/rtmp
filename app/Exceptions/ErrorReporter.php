<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Models\ErrorLog;
use App\Support\SecretMasker;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

/** Persists a sanitized error record and returns a public reference ID. */
class ErrorReporter
{
    public function report(Throwable $e, ?Request $request = null): string
    {
        $ref = 'ERR-'.now()->format('Ymd').'-'.Str::upper(Str::random(4));

        try {
            ErrorLog::create([
                'reference' => $ref,
                'tenant_id' => $request?->user()?->tenant_id,
                'user_id' => $request?->user()?->getAuthIdentifier(),
                'request_id' => $request?->attributes->get('request_id'),
                'module' => $this->module($e),
                'exception_class' => $e::class,
                'message' => SecretMasker::maskString(mb_substr($e->getMessage(), 0, 2000)),
                'file' => str_replace(base_path(), '', $e->getFile()),
                'line' => $e->getLine(),
                'method' => $request?->method(),
                'url' => $request ? SecretMasker::maskString(mb_substr($request->fullUrl(), 0, 2000)) : null,
                'ip_address' => $request?->ip(),
                'trace' => SecretMasker::maskString(mb_substr($e->getTraceAsString(), 0, 20000)),
            ]);
        } catch (Throwable) {
            // never let error logging crash the error page
        }

        return $ref;
    }

    private function module(Throwable $e): ?string
    {
        $file = $e->getFile();
        if (preg_match('#app/Domain/([A-Za-z]+)/#', $file, $m)) {
            return strtolower($m[1]);
        }
        if (str_contains($file, 'app/Http/Controllers/Api')) {
            return 'api';
        }
        if (str_contains($file, 'app/Http')) {
            return 'http';
        }

        return null;
    }
}
