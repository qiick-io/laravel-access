<?php

declare(strict_types=1);

namespace Qiick\Access\Laravel;

/**
 * Merges the shipped config/access.php under a product's published copy at
 * every depth (fleet convention F1). Laravel's mergeConfigFrom() merges only
 * the top level, so a product that published the file before a nested key
 * existed would read null for it instead of the package default.
 *
 * A list (guards, principal types, exempt routes) is a value of its own: the
 * published list replaces the default whole, never merged by index, so a
 * product can still remove a default entry.
 *
 * @internal
 */
final class ConfigDefaultsMerger
{
    /**
     * @param  array<array-key, mixed>  $defaults  the shipped config
     * @param  array<array-key, mixed>  $published  the product's config, which wins on every key it sets
     * @return array<array-key, mixed>
     */
    public static function merge(array $defaults, array $published): array
    {
        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $published)) {
                $published[$key] = $default;

                continue;
            }

            if (is_array($default) && ! array_is_list($default) && is_array($published[$key])) {
                $published[$key] = self::merge($default, $published[$key]);
            }
        }

        return $published;
    }
}
