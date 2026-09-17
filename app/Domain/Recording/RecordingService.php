<?php

declare(strict_types=1);

namespace App\Domain\Recording;

use App\Domain\Audit\AuditLogger;
use App\Models\Recording;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecordingService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Authorized download; never exposes the storage path. */
    public function download(Recording $recording): StreamedResponse
    {
        $disk = Storage::disk($recording->disk);
        if (! $disk->exists($recording->path)) {
            abort(404, 'Recording file not found');
        }
        $this->audit->log('recording.downloaded', $recording);
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', $recording->title).'.'.$recording->format;

        return $disk->download($recording->path, $name, ['Content-Type' => 'video/mp4', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function delete(Recording $recording): void
    {
        $disk = Storage::disk($recording->disk);
        if ($disk->exists($recording->path)) {
            $disk->delete($recording->path);
        }
        $recording->forceFill(['status' => 'deleted'])->save();
        $recording->delete();
        $this->audit->log('recording.deleted', $recording);
    }

    /** Move a local recording to the configured object storage disk (s3). */
    public function transfer(Recording $recording, string $targetDisk = 's3'): void
    {
        if ($recording->disk === $targetDisk) {
            return;
        }
        $recording->forceFill(['status' => 'transferring'])->save();
        $source = Storage::disk($recording->disk);
        $stream = $source->readStream($recording->path);
        if (! $stream) {
            $recording->forceFill(['status' => 'completed'])->save();
            throw new \RuntimeException('Cannot read recording file');
        }
        Storage::disk($targetDisk)->writeStream($recording->path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        $source->delete($recording->path);
        $recording->forceFill(['disk' => $targetDisk, 'status' => 'completed'])->save();
        $this->audit->log('recording.transferred', $recording, ['to' => $targetDisk]);
    }

    /** Delete expired recordings (scheduler). */
    public function purgeExpired(): int
    {
        $count = 0;
        foreach (Recording::withoutGlobalScopes()->whereNotNull('expires_at')->where('expires_at', '<', now())->whereIn('status', ['completed', 'failed'])->get() as $r) {
            $this->delete($r);
            $count++;
        }

        return $count;
    }

    public function totalSize(): int
    {
        return (int) Recording::sum('size_bytes');
    }
}
