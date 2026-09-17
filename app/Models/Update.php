<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Update extends Model
{
    use HasUlids;

    protected $fillable = ['state', 'status', 'previous_version', 'version', 'previous_commit', 'commit_sha', 'commit_message', 'commit_author', 'commit_date', 'repository', 'branch', 'changed_files', 'added_files', 'modified_files', 'deleted_files', 'download_size', 'archive_checksum', 'release_path', 'previous_release_path', 'backup_id', 'backup_ok', 'migration_ok', 'migration_ran', 'health_ok', 'rolled_back', 'error', 'release_notes', 'file_changes', 'started_by', 'started_at', 'completed_at', 'duration_seconds'];

    protected function casts(): array
    {
        return [
            'commit_date' => 'datetime',
            'backup_ok' => 'boolean',
            'migration_ok' => 'boolean',
            'migration_ran' => 'boolean',
            'health_ok' => 'boolean',
            'rolled_back' => 'boolean',
            'release_notes' => 'array',
            'file_changes' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function logs(): HasMany
    {
        return $this->hasMany(UpdateLog::class);
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }
}
