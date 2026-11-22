<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

/**
 * Simulates a Spatie/Eloquent user whose getRoleNames() stays warm after the
 * database row is demoted, until unsetRelation('roles') forces a reload (B2).
 */
final class StaleRoleSuperAdmin extends FakeAuthenticatable
{
    /** @var list<string> */
    private array $databaseRoleNames = ['super-admin'];

    /** @var list<string>|null */
    private ?array $warmRoleNames = null;

    /**
     * @return list<string>
     */
    public function getRoleNames(): array
    {
        if ($this->warmRoleNames !== null) {
            return $this->warmRoleNames;
        }

        $this->warmRoleNames = $this->databaseRoleNames;

        return $this->warmRoleNames;
    }

    public function demoteInDatabaseOnly(): void
    {
        $this->databaseRoleNames = [];
    }

    public function unsetRelation(string $relation): void
    {
        if ($relation === 'roles') {
            $this->warmRoleNames = null;
        }
    }

    public function roles(): object
    {
        return new class
        {
            public function get(mixed ...$arguments): array
            {
                return [];
            }
        };
    }
}
