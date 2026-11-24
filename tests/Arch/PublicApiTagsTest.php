<?php

declare(strict_types=1);

/*
 * Checklist item 14: the public API is frozen at 1.0, so every class,
 * interface, enum and trait in src/ says whether it is part of it.
 */

it('tags every unit in src as @api or @internal', function (): void {
    $untaggedFiles = [];
    $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($sourceFiles as $sourceFile) {
        $source = (string) file_get_contents($sourceFile->getPathname());

        if (preg_match('/^ \* @(api|internal)$/m', $source) !== 1) {
            $untaggedFiles[] = $sourceFile->getFilename();
        }
    }

    expect($untaggedFiles)->toBe([]);
});
