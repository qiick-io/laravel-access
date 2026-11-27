<?php

declare(strict_types=1);

namespace Qiick\Access\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Qiick\Access\Contracts\GroupRoleSource;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Laravel\AccessBootChecks;
use Qiick\Access\Laravel\EloquentGroupRoleSource;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Throwable;

/**
 * `access:check` (fleet convention F2): the deploy pipeline and the product's
 * `<app>:upgrade` run it before a request needs the package. It repeats the
 * boot checks and adds what boot must not do: reach the default database and
 * find the Spatie and package tables the resolver and the principals read.
 * One line per problem, exit 1 on any.
 *
 * @internal
 */
final class CheckAccessCommand extends Command
{
    protected $signature = 'access:check';

    protected $description = 'Check the qiick/laravel-access configuration, bindings and tables';

    /** Reads table metadata only; writes nothing. */
    public function handle(AccessBootChecks $checks, ConfigRepository $config, DatabaseManager $database): int
    {
        $problems = [];

        try {
            $checks->assert();
            $this->getLaravel()->make(EffectivePermissionResolver::class);
        } catch (Throwable $exception) {
            $problems[] = $exception->getMessage();
        }

        $problems = [...$problems, ...$this->tableProblems($config, $database)];

        if ($problems === []) {
            $this->info('qiick/laravel-access configuration, bindings and tables are ready.');

            return self::SUCCESS;
        }

        foreach ($problems as $problem) {
            $this->error(OutputFormatter::escape($problem));
        }

        return self::FAILURE;
    }

    /**
     * Spatie passes its configured table names straight to its relations, so
     * a missing or empty name is a problem of its own, not a default.
     *
     * @return list<string>
     */
    private function tableProblems(ConfigRepository $config, DatabaseManager $database): array
    {
        $problems = [];
        $tables = [];

        foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $spatieTable) {
            $tableName = $config->get("permission.table_names.{$spatieTable}");

            if (is_string($tableName) && $tableName !== '') {
                $tables[] = $tableName;
            } else {
                $problems[] = "permission.table_names.{$spatieTable} must be a non-empty table name.";
            }
        }

        // Required when the package migrates them or its own source reads
        // them (Externa 1.x owns the same tables with the migrations off);
        // a product GroupRoleSource keeps its own store.
        if ($config->get('access.migrations.groups') === true || $this->readsPackageGroupTables()) {
            $tables = [...$tables, 'user_groups', 'user_group_user', 'user_group_role'];
        }

        // The provider's default: the principals migration loads unless switched off.
        if ($config->get('access.migrations.principals', true) === true) {
            $tables[] = 'principals';
        }

        try {
            $connection = $database->connection();
            // The package writes through the primary, and hasTable() selects
            // through the read PDO, so the metadata is read on the writer.
            $connection->useWriteConnectionWhenReading();

            try {
                $schema = $connection->getSchemaBuilder();

                foreach ($tables as $table) {
                    if (! $schema->hasTable($table)) {
                        $problems[] = "Table [{$table}] is missing on the default connection; run the migrations.";
                    }
                }
            } finally {
                $connection->useWriteConnectionWhenReading(false);
            }
        } catch (Throwable) {
            return [...$problems, 'The default database connection ['.$database->getDefaultConnection().'] is unreachable.'];
        }

        return $problems;
    }

    /**
     * A product source that cannot be built here is reported by the resolver
     * check above; its tables are its own.
     */
    private function readsPackageGroupTables(): bool
    {
        try {
            return $this->getLaravel()->make(GroupRoleSource::class) instanceof EloquentGroupRoleSource;
        } catch (Throwable) {
            return false;
        }
    }
}
