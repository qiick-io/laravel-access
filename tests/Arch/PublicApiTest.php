<?php

declare(strict_types=1);

/*
 * Checklist item 14 and F12: the public API is frozen at 1.0, so every class,
 * interface, enum and trait in src/ says in its own docblock whether it is
 * part of it, and docs/public-api.md lists every @api one.
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

it('lists every @api class in docs/public-api.md, and nothing else (F12)', function (): void {
    $page = (string) file_get_contents(dirname(__DIR__, 2).'/docs/public-api.md');
    preg_match_all('/^- `(\w+)` \((?:class|interface|enum|trait)\)$/m', $page, $listed);
    $apiUnits = [];
    $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($sourceFiles as $sourceFile) {
        $source = (string) file_get_contents($sourceFile->getPathname());

        if (preg_match('/\/\*\*((?:(?!\*\/).)*)\*\/\n(?:\/\/[^\n]*\n)*(?:(?:final|abstract|readonly) )*(?:class|interface|enum|trait) (\w+)/s', $source, $documentedUnit) === 1
            && preg_match('/^ \* @api$/m', $documentedUnit[1]) === 1) {
            $apiUnits[] = $documentedUnit[2];
        }
    }

    $listedUnits = $listed[1];
    sort($apiUnits);
    sort($listedUnits);

    expect($listedUnits)->toBe($apiUnits);
});
