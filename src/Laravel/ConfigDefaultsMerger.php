<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

use RuntimeException;

/**
 * Merges the shipped config/access.php under a product's published copy at
 * every depth (fleet convention F1). Laravel's mergeConfigFrom() merges only
 * the top level, so a product that published the file before a nested key
 * existed would read null for it instead of the package default.
 *
 * A list (guards, principal types, exempt routes) is a value of its own: the
 * published list replaces the default whole, never merged by index, so a
 * product can still remove a default entry. A section that is a map in the
 * shipped file (two_factor, principals, migrations) must stay one: a list or
 * a scalar there is a typo, refused with its key instead of being filled
 * with defaults the product never asked for. An empty array is an empty map.
 *
 * @internal
 */
final class ConfigDefaultsMerger
{
    /**
     * @param  array<array-key, mixed>  $defaults  the shipped config
     * @param  mixed  $published  the product's config, which wins on every key it sets; null when not published
     * @param  string  $key  the config key of this level, for the error message
     * @return array<array-key, mixed>
     *
     * @throws RuntimeException naming the first section that is not a map
     */
    public static function merge(array $defaults, mixed $published, string $key = 'access'): array
    {
        $published ??= [];

        if (! is_array($published) || ($published !== [] && array_is_list($published))) {
            throw new RuntimeException("{$key} must be a map of settings, got ".get_debug_type($published).'.');
        }

        foreach ($defaults as $name => $default) {
            if (! array_key_exists($name, $published)) {
                $published[$name] = $default;

                continue;
            }

            if (is_array($default) && ! array_is_list($default)) {
                $published[$name] = self::merge($default, $published[$name], "{$key}.{$name}");
            }
        }

        return $published;
    }
}
