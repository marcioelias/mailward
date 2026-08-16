<?php

declare(strict_types=1);

namespace App\Actions;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reports whether the configured iRedMail database is reachable and looks like
 * the schema Mailward expects.
 *
 * `docs/decisions/0001-sql-backend-only.md` requires detecting the backend and
 * refusing with a clear message rather than failing obscurely later, and
 * `docs/00-overview.md` §8 requires the same for an unsupported schema. This
 * is the detection half; refusing is a feature that is not specified yet.
 */
final class InspectMailBackendAction
{
    /**
     * Tables Mailward reads in v1 (docs/02-domain.md §2–§11). A connection
     * that reaches a database without these is not an iRedMail account
     * database, whatever else it may be.
     *
     * @var list<string>
     */
    private const EXPECTED_TABLES = [
        'domain',
        'alias_domain',
        'mailbox',
        'forwardings',
        'alias',
        'domain_admins',
        'used_quota',
        'last_login',
        'deleted_mailboxes',
    ];

    /**
     * @return array{
     *     connected: bool,
     *     driver: string,
     *     database: ?string,
     *     error: ?string,
     *     missingTables: list<string>,
     *     counts: array<string, int>
     * }
     */
    public function handle(): array
    {
        $connection = DB::connection('vmail');
        $driver = $connection->getDriverName();

        try {
            $connection->getPdo();
        } catch (Throwable $e) {
            return [
                'connected' => false,
                'driver' => $driver,
                'database' => null,
                'error' => $e->getMessage(),
                'missingTables' => self::EXPECTED_TABLES,
                'counts' => [],
            ];
        }

        $missing = array_values(array_filter(
            self::EXPECTED_TABLES,
            fn (string $table): bool => ! $connection->getSchemaBuilder()->hasTable($table),
        ));

        $counts = [];

        foreach (['domain', 'mailbox', 'alias', 'domain_admins'] as $table) {
            if (! in_array($table, $missing, true)) {
                $counts[$table] = $connection->table($table)->count();
            }
        }

        return [
            'connected' => true,
            'driver' => $driver,
            'database' => $connection->getDatabaseName(),
            'error' => null,
            'missingTables' => $missing,
            'counts' => $counts,
        ];
    }
}
