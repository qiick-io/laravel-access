<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\ConfigurationUrlParser;
use RuntimeException;

/**
 * One effective connection name, one database (review of #43).
 *
 * Eloquent stamps the connection's effective name on every model it loads,
 * and the group member check and the resolver's principal key trust that
 * name. Laravel builds the effective name from the merged configuration:
 * a URL's query options and the writer's options can override `name`, so a
 * second database could report the default connection's name and its users
 * would pass for the default's. A connection alias repointed at another
 * database (purge or reconnect after a config change) would do the same to
 * models loaded before the switch.
 *
 * Listens to {@see ConnectionEstablished}, which Laravel dispatches when it
 * creates or reconnects a connection, before any query runs on it: the
 * connection's database identity (driver, host, port, socket, database,
 * prefix, schema) must match the identity of every configured writer variant
 * of the connection its name designates, and the identity first seen under
 * that name in this process. Otherwise the connection is dropped and the
 * call fails, every time it is retried.
 *
 * @internal
 */
final class ConnectionIdentityGuard
{
    /**
     * @var array<string, string> effective connection name => database identity
     */
    private array $identitiesByName = [];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly ConfigRepository $config,
    ) {}

    public function __invoke(ConnectionEstablished $event): void
    {
        $this->assertUnambiguous($event->connection);
    }

    /**
     * @throws RuntimeException when the connection's name already stands for another database
     */
    public function assertUnambiguous(Connection $connection): void
    {
        $name = $connection->getName();
        $connectionConfig = $connection->getConfig();

        // Laravel's factory always names a connection; anything else is not trusted.
        if ($name === null || ! is_array($connectionConfig)) {
            throw new RuntimeException('A database connection without a name or configuration cannot be checked.');
        }

        $identity = self::identityOf($connectionConfig);
        $expectedIdentities = $this->configuredIdentities($name);

        if (isset($this->identitiesByName[$name])) {
            $expectedIdentities[] = $this->identitiesByName[$name];
        }

        if (array_diff($expectedIdentities, [$identity]) === []) {
            $this->identitiesByName[$name] = $identity;

            return;
        }

        // Laravel caches the connection before it dispatches the event.
        foreach ($this->database->getConnections() as $key => $establishedConnection) {
            if ($establishedConnection === $connection) {
                $this->database->purge($key);
            }
        }

        throw new RuntimeException(
            "Database connection [{$name}] would stand for two databases: Eloquent identifies loaded models by that name. Give each database its own connection name (see docs/adoption.md).",
        );
    }

    /**
     * The identities the configured connection keyed by $name can have: one
     * per writer variant, after the URL options are merged as Laravel does.
     *
     * @return list<string>
     */
    private function configuredIdentities(string $name): array
    {
        $configuredConnection = $this->config->get("database.connections.{$name}");

        if (is_string($configuredConnection)) {
            $configuredConnection = ['url' => $configuredConnection];
        }

        if (! is_array($configuredConnection)) {
            return [];
        }

        $configuredOptions = [];

        foreach ($configuredConnection as $option => $value) {
            if (is_string($option)) {
                $configuredOptions[$option] = $value;
            }
        }

        $connectionConfig = (new ConfigurationUrlParser)->parseConfiguration($configuredOptions);

        // Laravel merges a writer variant over the shared options only for a
        // read/write connection; the identity ignores the read and write keys.
        $writerVariants = isset($connectionConfig['read']) ? $connectionConfig['write'] ?? [] : [];
        $writerVariants = is_array($writerVariants) && array_is_list($writerVariants) && $writerVariants !== [] ? $writerVariants : [$writerVariants];

        return array_map(
            static fn (mixed $writerVariant): string => self::identityOf(array_merge($connectionConfig, is_array($writerVariant) ? $writerVariant : [])),
            $writerVariants,
        );
    }

    /**
     * @param  array<array-key, mixed>  $connectionConfig
     */
    private static function identityOf(array $connectionConfig): string
    {
        // shortcut: two in-memory SQLite connections share this identity, though
        // each is its own database; only tests use them, so it stays unchecked.
        return json_encode([
            $connectionConfig['driver'] ?? null,
            $connectionConfig['host'] ?? null,
            $connectionConfig['port'] ?? null,
            $connectionConfig['unix_socket'] ?? null,
            $connectionConfig['database'] ?? null,
            $connectionConfig['prefix'] ?? '',
            $connectionConfig['search_path'] ?? $connectionConfig['schema'] ?? null,
        ], JSON_THROW_ON_ERROR);
    }
}
