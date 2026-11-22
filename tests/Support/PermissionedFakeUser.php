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
        $roleNames = $this->roleNames;

        return new class($roleNames)
        {
            /**
             * @param  list<string>  $roleNames
             */
            public function __construct(private array $roleNames) {}

            public function select(string $columns): self
            {
                return $this;
            }

            /**
             * @return list<object{getAttribute(string): int|string}>
             */
            public function get(mixed ...$arguments): array
            {
                $ids = [];

                foreach (array_values(array_filter($this->roleNames, static fn (string $name): bool => $name !== '')) as $index => $roleName) {
                    $ids[] = new class($index + 1)
                    {
                        public function __construct(private int $id) {}

                        public function getAttribute(string $key): int
                        {
                            return $this->id;
                        }
                    };
                }

                return $ids;
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

    /** @var list<list<string>> */
    public array $loadMissingCalls = [];

    /**
     * @param  array<int, string>  $relations
     */
    public function loadMissing(array $relations): void
    {
        $this->loadMissingCalls[] = array_values($relations);
    }

    /** @var list<string> */
    public array $unsetRelations = [];

    public function unsetRelation(string $relation): void
    {
        $this->unsetRelations[] = $relation;
    }

    /**
     * @param  list<string>  $permissionNames
     */
    public function setPermissionNames(array $permissionNames): void
    {
        $this->permissionNames = $permissionNames;
    }
}
