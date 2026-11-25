<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use BackedEnum;
use InvalidArgumentException;
use Qiick\Access\Contracts\PermissionVocabulary;
use UnitEnum;

/**
 * PermissionVocabulary over a product string-backed PermissionEnum class
 * configured as access.permission_enum (design §2.2).
 *
 * @api
 */
final class SpatiePermissionVocabulary implements PermissionVocabulary
{
    /** @var class-string<BackedEnum&UnitEnum> */
    private readonly string $enumClass;

    public function __construct(string $enumClass)
    {
        if (! enum_exists($enumClass) || ! is_subclass_of($enumClass, BackedEnum::class)) {
            throw new InvalidArgumentException(
                'access.permission_enum must be a backed enum class name.',
            );
        }

        $this->enumClass = $enumClass;
    }

    public function isPermission(string $ability): bool
    {
        foreach ($this->enumClass::cases() as $case) {
            if ((string) $case->value === $ability) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        $names = [];
        foreach ($this->enumClass::cases() as $case) {
            $names[] = (string) $case->value;
        }

        return $names;
    }
}
