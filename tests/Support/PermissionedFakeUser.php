<?php

declare(strict_types=1);

namespace Qiick\Access\Tests\Support;

/**
 * Authenticatable with Spatie-like role and permission helpers for resolver tests.
 */
final class PermissionedFakeUser extends FakeAuthenticatable
{
    public int $permissionCalls = 0;

    /**
     * @param  list<string>  $roleNames
     * @param  list<string>  $permissionNames
     */
    public function __construct(
        string|int $id,
        private array $roleNames = [],
        private array $permissionNames = [],
    ) {
        parent::__construct($id);
    }

    public function roles(): object
    {
        return new class
        {
            public function select(string $columns): self
            {
                return $this;
            }

            /**
             * @return list<object>
             */
            public function get(mixed ...$arguments): array
            {
                return [];
            }
        };
    }

    /**
     * @return list<string>
     */
    public function getRoleNames(): array
    {
        return $this->roleNames;
    }

    /**
     * @return list<object{getAttribute(string): string}>
     */
    public function getAllPermissions(): array
    {
        $this->permissionCalls++;

        return array_map(
            static fn (string $name): object => new class($name)
            {
                public function __construct(private string $name) {}

                public function getAttribute(string $key): string
                {
                    return $this->name;
                }
            },
            $this->permissionNames,
        );
    }

    /**
     * @param  array<int, string>  $relations
     */
    public function loadMissing(array $relations): void {}

    /**
     * @param  list<string>  $permissionNames
     */
    public function setPermissionNames(array $permissionNames): void
    {
        $this->permissionNames = $permissionNames;
    }
}
