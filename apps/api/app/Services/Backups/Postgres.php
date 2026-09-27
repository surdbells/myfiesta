<?php

namespace App\Services\Backups;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * What the backup and restore commands need to know about a Postgres
 * connection, and to hand to pg_dump and pg_restore.
 *
 * The password goes to the tools in PGPASSWORD, never as an argument: an
 * argument is visible to anybody on the host who can list processes.
 */
final class Postgres
{
    /**
     * The connection's settings as Laravel resolved them, DB_URL included.
     *
     * @return array<string, mixed>
     */
    public static function config(string $connection): array
    {
        $config = DB::connection($connection)->getConfig();

        if (($config['driver'] ?? null) !== 'pgsql') {
            throw new RuntimeException("The {$connection} connection is not Postgres, and only Postgres is backed up here.");
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<string>
     */
    public static function arguments(array $config): array
    {
        $host = $config['host'] ?? null;

        return array_values(array_filter([
            filled($host) ? '--host='.(is_array($host) ? $host[0] : $host) : null,
            filled($config['port'] ?? null) ? '--port='.$config['port'] : null,
            filled($config['username'] ?? null) ? '--username='.$config['username'] : null,
            // Never asks: a prompt nobody can answer would hang the scheduler.
            '--no-password',
        ]));
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, string>
     */
    public static function environment(array $config): array
    {
        return array_filter([
            'PGPASSWORD' => (string) ($config['password'] ?? ''),
            'PGSSLMODE' => (string) ($config['sslmode'] ?? ''),
            'PGAPPNAME' => 'myfiesta-backup',
        ], fn (string $value) => $value !== '');
    }

    /**
     * A connection of its own, so nothing else shares its transaction.
     *
     * @param  array<string, mixed>  $config
     */
    public static function open(array $config, string $as): Connection
    {
        config(["database.connections.{$as}" => array_merge($config, ['name' => $as])]);
        DB::purge($as);

        return DB::connection($as);
    }

    public static function close(string $as): void
    {
        DB::purge($as);
        config(["database.connections.{$as}" => null]);
    }

    /**
     * Every table's row count, keyed schema.table.
     *
     * @return array<string, int>
     */
    public static function rowCounts(Connection $connection): array
    {
        $tables = $connection->select(
            "select schemaname, tablename from pg_tables where schemaname not in ('pg_catalog', 'information_schema') order by schemaname, tablename",
        );

        $counts = [];

        foreach ($tables as $table) {
            $name = self::quote($table->schemaname).'.'.self::quote($table->tablename);
            $counts[$table->schemaname.'.'.$table->tablename] = (int) $connection->selectOne("select count(*) as n from {$name}")->n;
        }

        return $counts;
    }

    public static function quote(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }
}
