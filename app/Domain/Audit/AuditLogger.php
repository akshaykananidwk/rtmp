<?php

declare(strict_types=1);

namespace App\Domain\Audit;

use App\Domain\Tenancy\TenantContext;
use App\Models\ActivityLog;
use App\Support\SecretMasker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

class AuditLogger
{
    public function __construct(private readonly TenantContext $tenant) {}

    public function log(string $action, ?Model $subject = null, array $properties = [], string $result = 'success', ?string $userId = null): void
    {
        try {
            $request = app()->bound('request') ? request() : null;
            $user = auth()->user();

            ActivityLog::create([
                'tenant_id' => $this->tenant->id() ?? $user?->tenant_id,
                'user_id' => $userId ?? $user?->getAuthIdentifier(),
                'action' => $action,
                'subject_type' => $subject ? $subject::class : null,
                'subject_id' => $subject?->getKey(),
                'ip_address' => $request?->ip(),
                'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : 'console',
                'result' => $result,
                'properties' => SecretMasker::maskArray($properties),
                'request_id' => $request?->attributes->get('request_id'),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Audit log write failed: '.SecretMasker::maskString($e->getMessage()));
        }
    }

    public function failure(string $action, ?Model $subject = null, array $properties = []): void
    {
        $this->log($action, $subject, $properties, 'failure');
    }
}
