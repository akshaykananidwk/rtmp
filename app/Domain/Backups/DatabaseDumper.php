<?php

declare(strict_types=1);

namespace App\Domain\Backups;

use App\Support\BinaryLocator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Produces a SQL dump. Uses mysqldump when available (fast, complete), otherwise a
 * pure-PHP dumper (shared hosting without shell). SQLite databases are copied as-is.
 */
class DatabaseDumper
{
    public function dump(string $targetFile, ?string $connection = null): array
    {
        $connection ??= config('database.default');
        $driver = config("database.connections.$connection.driver");

        return match ($driver) {
            'mysql', 'mariadb' => $this->dumpMysql($targetFile, $connection),
            'sqlite' => $this->dumpSqlite($targetFile, $connection),
            'pgsql' => $this->dumpPgsql($targetFile, $connection),
            default => throw new \RuntimeException("Unsupported database driver for backup: $driver"),
        };
    }

    private function dumpSqlite(string $targetFile, string $connection): array
    {
        $db = config("database.connections.$connection.database");
        if ($db === ':memory:') {
            // Dump memory DB to SQL via PHP dumper
            return $this->dumpMysqlViaPhp($targetFile, $connection, true);
        }
        if (! copy($db, $targetFile)) {
            throw new \RuntimeException('Could not copy SQLite database');
        }

        return ['method' => 'sqlite-copy', 'format' => 'sqlite'];
    }

    private function dumpMysql(string $targetFile, string $connection): array
    {
        $cfg = config("database.connections.$connection");
        $bin = BinaryLocator::find('mysqldump') ?? BinaryLocator::find('mariadb-dump');

        if ($bin && function_exists('proc_open')) {
            $cnf = tempnam(sys_get_temp_dir(), 'akcnf');
            file_put_contents($cnf, "[client]\nuser=\"{$cfg['username']}\"\npassword=\"".str_replace('"', '\"', (string) $cfg['password'])."\"\nhost=\"{$cfg['host']}\"\nport=\"".($cfg['port'] ?? 3306)."\"\n");
            chmod($cnf, 0600);
            try {
                $process = new Process([$bin, '--defaults-extra-file='.$cnf, '--single-transaction', '--quick', '--routines', '--triggers', '--skip-lock-tables', '--result-file='.$targetFile, $cfg['database']]);
                $process->setTimeout(1800);
                $process->run();
                if ($process->isSuccessful() && filesize($targetFile) > 0) {
                    return ['method' => 'mysqldump', 'format' => 'sql'];
                }
            } finally {
                @unlink($cnf);
            }
        }

        return $this->dumpMysqlViaPhp($targetFile, $connection);
    }

    private function dumpPgsql(string $targetFile, string $connection): array
    {
        $cfg = config("database.connections.$connection");
        $bin = BinaryLocator::find('pg_dump');
        if (! $bin) {
            throw new \RuntimeException('pg_dump not found');
        }
        $process = new Process([$bin, '--no-owner', '--format=plain', '-f', $targetFile, $cfg['database']], null, ['PGHOST' => $cfg['host'], 'PGPORT' => (string) ($cfg['port'] ?? 5432), 'PGUSER' => $cfg['username'], 'PGPASSWORD' => (string) $cfg['password']]);
        $process->setTimeout(1800);
        $process->mustRun();

        return ['method' => 'pg_dump', 'format' => 'sql'];
    }

    /** Pure-PHP SQL dumper (works on shared hosting). */
    private function dumpMysqlViaPhp(string $targetFile, string $connection, bool $sqlite = false): array
    {
        $conn = DB::connection($connection);
        $pdo = $conn->getPdo();
        $fh = fopen($targetFile, 'w');
        if (! $fh) {
            throw new \RuntimeException('Cannot open dump file for writing');
        }

        fwrite($fh, '-- AK Computer backup '.now()->toDateTimeString()."\n");
        if (! $sqlite) {
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
        }

        $tables = $sqlite
            ? array_map(fn ($r) => $r->name, $conn->select("select name from sqlite_master where type='table' and name not like 'sqlite_%'"))
            : array_map(fn ($r) => array_values((array) $r)[0], $conn->select('SHOW TABLES'));

        foreach ($tables as $table) {
            $q = $sqlite ? '"' : '`';
            if ($sqlite) {
                $create = $conn->selectOne("select sql from sqlite_master where type='table' and name = ?", [$table])->sql;
            } else {
                $create = $conn->selectOne("SHOW CREATE TABLE `$table`")->{'Create Table'};
            }
            fwrite($fh, "DROP TABLE IF EXISTS {$q}{$table}{$q};\n{$create};\n\n");

            $offset = 0;
            $chunk = 500;
            do {
                $rows = $conn->table($table)->offset($offset)->limit($chunk)->get();
                if ($rows->isEmpty()) {
                    break;
                }
                $cols = array_keys((array) $rows->first());
                $colList = implode(', ', array_map(fn ($c) => $q.$c.$q, $cols));
                $values = [];
                foreach ($rows as $row) {
                    $vals = [];
                    foreach ((array) $row as $v) {
                        $vals[] = $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $pdo->quote((string) $v));
                    }
                    $values[] = '('.implode(', ', $vals).')';
                }
                fwrite($fh, "INSERT INTO {$q}{$table}{$q} ($colList) VALUES\n".implode(",\n", $values).";\n");
                $offset += $chunk;
            } while ($rows->count() === $chunk);
            fwrite($fh, "\n");
        }

        if (! $sqlite) {
            fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        }
        fclose($fh);

        return ['method' => 'php', 'format' => 'sql'];
    }

    /** Restore a dump produced by dump(). */
    public function restore(string $file, ?string $connection = null): void
    {
        $connection ??= config('database.default');
        $driver = config("database.connections.$connection.driver");

        if ($driver === 'sqlite') {
            $db = config("database.connections.$connection.database");
            if ($db !== ':memory:') {
                DB::purge($connection);
                if (! copy($file, $db)) {
                    throw new \RuntimeException('Could not restore SQLite database file');
                }
                DB::reconnect($connection);

                return;
            }
        }

        $sql = file_get_contents($file);
        if ($sql === false) {
            throw new \RuntimeException('Cannot read dump file');
        }
        $conn = DB::connection($connection);
        $pdo = $conn->getPdo();
        $pdo->exec($sql) === false && throw new \RuntimeException('Restore failed');
    }
}
