<?php

declare(strict_types=1);

/*
 * Checklist item 14: the public API is frozen at 1.0, so every class,
 * interface, enum and trait in src/ says in its own docblock whether it is
 * part of it.
 */

it('tags every class, interface, enum and trait in src as @api or @internal', function (): void {
    $untaggedUnits = [];
    $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($sourceFiles as $sourceFile) {
        $source = (string) file_get_contents($sourceFile->getPathname());
        preg_match_all('/^(?:(?:final|abstract|readonly) )*(?:class|interface|enum|trait) (\w+)/m', $source, $declarations);
        // The docblock directly above the declaration; line comments may sit between.
        preg_match_all('/\/\*\*((?:(?!\*\/).)*)\*\/\n(?:\/\/[^\n]*\n)*(?:(?:final|abstract|readonly) )*(?:class|interface|enum|trait) (\w+)/s', $source, $documentedUnits, PREG_SET_ORDER);
        $taggedUnitNames = array_map(
            static fn (array $documentedUnit): string => $documentedUnit[2],
            array_filter($documentedUnits, static fn (array $documentedUnit): bool => preg_match('/^ \* @(api|internal)$/m', $documentedUnit[1]) === 1),
        );

        foreach (array_diff($declarations[1], $taggedUnitNames) as $untaggedUnitName) {
            $untaggedUnits[] = $sourceFile->getFilename().': '.$untaggedUnitName;
        }
    }

    expect($untaggedUnits)->toBe([]);
});
