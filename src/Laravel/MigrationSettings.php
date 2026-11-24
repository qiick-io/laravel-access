<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use LogicException;

/**
 * Config the package migrations read when they run (design §15.3: changing it
 * later changes nothing in the database). Shared by the group and principal
 * migrations so both point their user foreign keys at the same table.
 */
final class MigrationSettings
{
    /**
     * The users table: access.users_table, else the table of the auth users
     * provider model, else "users".
     */
    public static function usersTable(): string
    {
        $configured = config('access.users_table');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $modelClass = config('auth.providers.users.model');

        if (is_string($modelClass) && is_a($modelClass, Model::class, true)) {
            return (new $modelClass)->getTable();
        }

        return 'users';
    }

    /**
     * The Eloquent users model (auth.providers.users.model) that principals
     * and group memberships belong to.
     *
     * @return class-string<Model>
     *
     * @throws LogicException when the configured users model is not an Eloquent model
     */
    public static function userModel(): string
    {
        $modelClass = config('auth.providers.users.model');

        if (! is_string($modelClass) || ! is_a($modelClass, Model::class, true)) {
            throw new LogicException('auth.providers.users.model must be an Eloquent model for principals.');
        }

        return $modelClass;
    }

    public static function isOrganisationRequired(): bool
    {
        return config('access.principals.organisation_required') === true;
    }

    /**
     * Principal types allowed without an organisation when organisations are
     * required (and only then). They are interpolated into a CHECK constraint,
     * so only plain lower-case type names are accepted.
     *
     * @return list<string>
     *
     * @throws InvalidArgumentException on anything but a list of type names
     */
    public static function instanceTypes(): array
    {
        $instanceTypes = config('access.principals.instance_types', []);

        if (! is_array($instanceTypes) || ! array_is_list($instanceTypes)) {
            throw new InvalidArgumentException('access.principals.instance_types must be a list of principal types.');
        }

        foreach ($instanceTypes as $instanceType) {
            if (! is_string($instanceType) || preg_match('/^[a-z][a-z0-9_]{0,31}$/', $instanceType) !== 1) {
                throw new InvalidArgumentException('access.principals.instance_types entries must be lower-case type names of at most 32 characters.');
            }
        }

        // Without required organisations there is no singleton index, so an
        // instance principal could not be kept unique (review N4 / B5).
        if ($instanceTypes !== [] && ! self::isOrganisationRequired()) {
            throw new InvalidArgumentException('access.principals.instance_types needs access.principals.organisation_required set to true.');
        }

        return $instanceTypes;
    }
}
