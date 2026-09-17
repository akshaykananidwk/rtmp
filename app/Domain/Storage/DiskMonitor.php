<?php

declare(strict_types=1);

namespace App\Domain\Storage;

class DiskMonitor
{
    public function usage(?string $path = null): array
    {
        $path ??= storage_path();
        $total = @disk_total_space($path) ?: 0;
        $free = @disk_free_space($path) ?: 0;
        $used = max(0, $total - $free);
        $percent = $total > 0 ? (int) round($used / $total * 100) : 0;

        $level = 'ok';
        if ($percent >= (int) config('akstream.disk.critical_percent', 90)) {
            $level = 'critical';
        } elseif ($percent >= (int) config('akstream.disk.warning_percent', 80)) {
            $level = 'warning';
        }

        return ['path' => $path, 'total' => (int) $total, 'free' => (int) $free, 'used' => (int) $used, 'percent' => $percent, 'level' => $level];
    }
}
