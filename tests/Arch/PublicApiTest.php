<?php

declare(strict_types=1);

/*
 * Checklist item 14 and F12: the public API is frozen at 1.0, so every class,
 * interface, enum and trait in src/ says in its own docblock whether it is
 * part of it, and docs/public-api.md lists every @api one under its
 * namespace with its kind.
 */

/**
 * Every declaration in src/ with the docblock right above it.
 *
 * @return list<array{file: string, name: string, kind: string, tag: string|null}>
 */
function sourceDeclarations(): array
{
    $declarations = [];
    $sourceFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS));

    foreach ($sourceFiles as $sourceFile) {
        $source = (string) file_get_contents($sourceFile->getPathname());
        preg_match('/^namespace ([^;]+);/m', $source, $namespace);
        preg_match_all('/^(?:(?:final|abstract|readonly) )*(class|interface|enum|trait) (\w+)/m', $source, $declared, PREG_SET_ORDER);
        // The docblock directly above the declaration; line comments may sit between.
        preg_match_all('/\/\*\*((?:(?!\*\/).)*)\*\/\n(?:\/\/[^\n]*\n)*(?:(?:final|abstract|readonly) )*(?:class|interface|enum|trait) (\w+)/s', $source, $documented, PREG_SET_ORDER);
        $tagsByName = [];

        foreach ($documented as [, $docblock, $name]) {
            $tagsByName[$name] = preg_match('/^ \* @(api|internal)$/m', $docblock, $tag) === 1 ? $tag[1] : null;
        }

        foreach ($declared as [, $kind, $name]) {
            $declarations[] = [
                'file' => $sourceFile->getFilename(),
                'name' => ($namespace[1] ?? '').'\\'.$name,
                'kind' => $kind,
                'tag' => $tagsByName[$name] ?? null,
            ];
        }
    }

    return $declarations;
}

it('tags every class, interface, enum and trait in src as @api or @internal', function (): void {
    $untagged = array_map(
        static fn (array $declaration): string => $declaration['file'].': '.$declaration['name'],
        array_filter(sourceDeclarations(), static fn (array $declaration): bool => $declaration['tag'] === null),
    );

    expect(array_values($untagged))->toBe([]);
});

it('lists every @api unit in docs/public-api.md under its namespace and kind, and nothing else (F12)', function (): void {
    $listed = [];
    $namespace = '';

    foreach (explode("\n", (string) file_get_contents(dirname(__DIR__, 2).'/docs/public-api.md')) as $line) {
        if (preg_match('/^### `([^`]+)`$/', $line, $heading) === 1) {
            $namespace = $heading[1];
        } elseif (preg_match('/^- `(\w+)` \((class|interface|enum|trait)\)$/', $line, $entry) === 1) {
            $listed[] = $namespace.'\\'.$entry[1].' ('.$entry[2].')';
        }
    }

    $public = array_map(
        static fn (array $declaration): string => $declaration['name'].' ('.$declaration['kind'].')',
        array_filter(sourceDeclarations(), static fn (array $declaration): bool => $declaration['tag'] === 'api'),
    );
    sort($listed);
    sort($public);

    expect($listed)->toBe($public);
});
