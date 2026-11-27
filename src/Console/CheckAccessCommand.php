<?php

declare(strict_types=1);

namespace Qiick\Access\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Qiick\Access\EffectivePermissionResolver;
use Qiick\Access\Laravel\AccessBootChecks;
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
     * @return list<string>
     */
    private function tableProblems(ConfigRepository $config, DatabaseManager $database): array
    {
        $tables = [];

        foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'] as $spatieTable) {
            $tableName = $config->get("permission.table_names.{$spatieTable}");
            $tables[] = is_string($tableName) && $tableName !== '' ? $tableName : $spatieTable;
        }

        // Externa 1.x switches the group migrations off and owns the same tables.
        $tables = [...$tables, 'user_groups', 'user_group_user', 'user_group_role'];

        if ($config->get('access.migrations.principals') === true) {
            $tables[] = 'principals';
        }

        $problems = [];

        try {
            $schema = $database->connection()->getSchemaBuilder();

            foreach ($tables as $table) {
                if (! $schema->hasTable($table)) {
                    $problems[] = "Table [{$table}] is missing on the default connection; run the migrations.";
                }
            }
        } catch (Throwable) {
            return ['The default database connection ['.$database->getDefaultConnection().'] is unreachable.'];
        }

        return $problems;
    }
}
