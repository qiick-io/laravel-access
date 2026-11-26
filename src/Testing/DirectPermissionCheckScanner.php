<?php

declare(strict_types=1);

namespace Qiick\Access\Testing;

use BackedEnum;
use DateTimeImmutable;
use InvalidArgumentException;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The AC9 static check (design §6 / §7), run from a product's test suite:
 *
 * - inside a Policy (a class named `*Policy`, or in a `Policies` namespace),
 *   no Gate ability call (`can`, `cannot`, `cant`, `canAny`, `allows`,
 *   `denies`, `authorize`, `inspect`) may take a permission: Policies check
 *   permissions through the resolver (R5). An ability that cannot be read
 *   statically (a variable, a constant, a spread) and any dynamically named
 *   call fail too. Role-only super-admin predicates and super-admin role
 *   checks fail because policies must account for the verified actor;
 * - outside Policies and the Inertia share, every direct `hasPermission()` /
 *   `hasAssignedPermission()` must be listed by site (`Class::method`, or the
 *   file path outside classes) in `access.direct_checks` of
 *   qiick-standards.json, with a reason, a decision link and a `review_by`
 *   date that has not passed. A listed site with no check left fails, so the
 *   list only shrinks.
 *
 * It reads PHP tokens, not text: comments, docblocks and strings never count
 * as calls, and class names resolve through the file's namespace and `use`
 * imports. A watched method name written as a string (`[$resolver,
 * 'hasPermission']`, `$object->{'can'}(...)`, a name held in a variable)
 * counts as a call, and a trait alias of a watched method (`use T {
 * hasPermission as checkPermission; }`) counts as that method in every scanned
 * file. Inside a Policy, class attributes count as part of the class, and a
 * static `Gate::` method outside the known Gate API (a macro) fails. Known
 * limit: a name assembled from pieces (`'has'.'Permission'`) or read from
 * configuration at runtime is not seen outside Policies; inside a Policy any
 * dynamically named call fails anyway.
 *
 * @api
 */
final class DirectPermissionCheckScanner
{
    private readonly string $permissionEnumClass;

    /** @var list<string> */
    private readonly array $permissionNames;

    private readonly DateTimeImmutable $today;

    /** The one site allowed to read permissions directly: the Inertia share (design §6). */
    private readonly string $inertiaShareSite;

    /**
     * Trait method aliases of watched methods found in the scanned files
     * (`use T { hasPermission as checkPermission; }`): lower-cased alias =>
     * lower-cased watched name.
     *
     * @var array<string, string>
     */
    private array $methodAliases = [];

    /**
     * @param  string  $permissionEnumClass  the product's string-backed PermissionEnum
     * @param  string  $inertiaShareClass  the product's Inertia middleware, whose `share()` may read permissions
     *
     * @throws InvalidArgumentException when the class is not a string-backed enum
     */
    public function __construct(
        string $permissionEnumClass,
        ?DateTimeImmutable $today = null,
        string $inertiaShareClass = 'App\Http\Middleware\HandleInertiaRequests',
    ) {
        $permissionNames = [];

        foreach (is_subclass_of($permissionEnumClass, BackedEnum::class) ? $permissionEnumClass::cases() : [null] as $permission) {
            if (! is_string($permission?->value)) {
                throw new InvalidArgumentException("[{$permissionEnumClass}] must be a string-backed enum.");
            }

            $permissionNames[] = $permission->value;
        }

        $this->permissionEnumClass = strtolower(ltrim($permissionEnumClass, '\\'));
        $this->permissionNames = $permissionNames;
        $this->today = $today ?? new DateTimeImmutable('today');
        $this->inertiaShareSite = strtolower(ltrim($inertiaShareClass, '\\')).'::share';
    }

    /**
     * @param  string  $basePath  product root; reported paths are relative to it
     * @param  list<string>  $paths  directories or PHP files, relative to $basePath
     * @param  string  $standardsFile  path to qiick-standards.json
     * @return list<string> one line per violation: files in path order, then the standards file
     *
     * @throws InvalidArgumentException on a missing path or an unreadable standards file
     */
    public function scan(string $basePath, array $paths, string $standardsFile): array
    {
        $listedSites = [];
        $standardsViolations = $this->validateListedSites($this->readDirectChecks($standardsFile), $listedSites);
        $foundSites = [];
        $violations = [];
        $tokensByFile = [];

        foreach ($this->phpFiles($basePath, $paths) as $relativePath => $absolutePath) {
            $tokensByFile[$relativePath] = array_values(array_filter(
                PhpToken::tokenize((string) file_get_contents($absolutePath)),
                static fn (PhpToken $token): bool => ! $token->isIgnorable(),
            ));
        }

        // A trait alias declared in one file can be called from any other.
        $this->methodAliases = array_merge(...array_map($this->readMethodAliases(...), array_values($tokensByFile)));

        foreach ($tokensByFile as $relativePath => $tokens) {
            foreach ($this->scanFile($relativePath, $tokens) as [$line, $site, $message]) {
                if ($message === null) {
                    $foundSites[] = strtolower($site);

                    if (isset($listedSites[strtolower($site)]) || strtolower($site) === $this->inertiaShareSite) {
                        continue;
                    }

                    $message = "direct hasPermission() at site {$site} is not listed in qiick-standards.json access.direct_checks (AC9).";
                } else {
                    $message = "in {$site}: {$message}";
                }

                $violations[] = "{$relativePath}:{$line} {$message}";
            }
        }

        foreach ($listedSites as $siteKey => $site) {
            if (! in_array($siteKey, $foundSites, true)) {
                $standardsViolations[] = "qiick-standards.json access.direct_checks [{$site}]: no direct check at this site any more; remove the entry (the list only shrinks).";
            }
        }

        return [...$violations, ...$standardsViolations];
    }

    /**
     * @return list<mixed>
     */
    private function readDirectChecks(string $standardsFile): array
    {
        if (! is_file($standardsFile)) {
            throw new InvalidArgumentException("Standards file [{$standardsFile}] does not exist.");
        }

        $standards = json_decode((string) file_get_contents($standardsFile), true);

        if (! is_array($standards)) {
            throw new InvalidArgumentException("Standards file [{$standardsFile}] is not valid JSON.");
        }

        $access = $standards['access'] ?? [];
        $directChecks = is_array($access) ? ($access['direct_checks'] ?? []) : null;

        if (! is_array($directChecks) || ! array_is_list($directChecks)) {
            throw new InvalidArgumentException('qiick-standards.json access.direct_checks must be a list of entries.');
        }

        return $directChecks;
    }

    /**
     * Fills $listedSites (lower-cased site => site as written) with the
     * entries that are complete and still under review; returns the problems.
     *
     * @param  list<mixed>  $directChecks
     * @param  array<string, string>  $listedSites
     * @return list<string>
     */
    private function validateListedSites(array $directChecks, array &$listedSites): array
    {
        $violations = [];

        foreach ($directChecks as $index => $entry) {
            $entry = is_array($entry) ? $entry : [];
            $site = $this->nonEmptyString($entry['site'] ?? null);
            $problems = [];

            foreach (['site', 'reason', 'decision'] as $field) {
                if ($this->nonEmptyString($entry[$field] ?? null) === null) {
                    $problems[] = "needs a {$field}";
                }
            }

            $reviewBy = $entry['review_by'] ?? null;
            $reviewDate = is_string($reviewBy) ? DateTimeImmutable::createFromFormat('!Y-m-d', $reviewBy) : false;

            if ($reviewDate === false || $reviewDate->format('Y-m-d') !== $reviewBy) {
                $problems[] = 'review_by must be a Y-m-d date';
            } elseif ($reviewDate < $this->today) {
                $problems[] = "review_by {$reviewBy} has passed; review the exception or remove the call";
            }

            if ($site === null || $problems !== []) {
                $label = $site === null ? "[{$index}]" : "[{$index}] [{$site}]";
                $violations[] = "qiick-standards.json access.direct_checks{$label}: ".implode('; ', $problems).'.';

                continue;
            }

            $listedSites[strtolower(ltrim($site, '\\'))] = $site;
        }

        return $violations;
    }

    private function nonEmptyString(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    /**
     * @param  list<string>  $paths
     * @return array<string, string> relative path => absolute path, in path order
     */
    private function phpFiles(string $basePath, array $paths): array
    {
        $files = [];

        foreach ($paths as $path) {
            $relativePath = trim($path, '/');
            $absolutePath = "{$basePath}/{$relativePath}";
            $pathFiles = [];

            if (is_file($absolutePath)) {
                $pathFiles[$relativePath] = $absolutePath;
            } elseif (is_dir($absolutePath)) {
                /** @var SplFileInfo $file */
                foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($absolutePath, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                    if ($file->getExtension() === 'php') {
                        $pathFiles[substr($file->getPathname(), strlen($basePath) + 1)] = $file->getPathname();
                    }
                }
            } else {
                throw new InvalidArgumentException("Scan path [{$path}] does not exist under [{$basePath}].");
            }

            ksort($pathFiles);
            $files = [...$files, ...$pathFiles];
        }

        return $files;
    }

    /**
     * Findings of one file in token order: [line, site, null] for a direct
     * check (the caller decides whether it is listed) or [line, site,
     * message] for a Policy violation.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<array{int, string, string|null}>
     */
    private function scanFile(string $relativePath, array $tokens): array
    {
        $namespace = '';
        /** @var array<string, string> $imports lower-cased alias => class name */
        $imports = [];
        /** @var list<array{kind: string, name: string}|null> $braces one entry per open brace: the scope it opened, if any */
        $braces = [];
        $pendingScope = null;
        $findings = [];
        /** @var array{string, int}|null $attributeOwner the class a pre-body attribute belongs to, and the attribute's end */
        $attributeOwner = null;

        foreach ($tokens as $index => $token) {
            if ($token->is(T_ATTRIBUTE) && $attributeOwner === null) {
                $attributeOwner = $this->attributeOwner($tokens, $index, $namespace);
            }

            $previousText = ($tokens[$index - 1] ?? null)?->text;
            $nextToken = $tokens[$index + 1] ?? $token;
            $scopes = array_filter($braces);

            if ($token->is(T_NAMESPACE) && $nextToken->is([T_STRING, T_NAME_QUALIFIED])) {
                $namespace = $nextToken->text;
                $imports = [];
            } elseif ($token->is(T_USE) && $scopes === [] && $previousText !== ')') {
                $imports = [...$imports, ...$this->readImports($tokens, $index)];
            } elseif ($token->is([T_CLASS, T_TRAIT, T_ENUM]) && $previousText !== '::') {
                $pendingScope = [
                    'kind' => $previousText === 'new' ? 'anonymous' : 'class',
                    'name' => ltrim($namespace.'\\'.$nextToken->text, '\\'),
                ];
            } elseif ($token->is(T_FUNCTION)) {
                $nameToken = $nextToken->text === '&' ? ($tokens[$index + 2] ?? $token) : $nextToken;
                $pendingScope = $nameToken->is(T_STRING) ? ['kind' => 'function', 'name' => $nameToken->text] : $pendingScope;
            } elseif ($token->text === ';') {
                // An abstract or interface method has no body.
                $pendingScope = null;
            } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $braces[] = $pendingScope;
                $pendingScope = null;
            } elseif ($token->text === '}') {
                array_pop($braces);
            } else {
                [$site, $isPolicy] = $attributeOwner === null
                    ? $this->site($scopes, $relativePath)
                    : [$attributeOwner[0], $this->isPolicyClass($attributeOwner[0])];
                $finding = $this->judgeToken($tokens, $index, $isPolicy, $namespace, $imports);

                if ($finding !== false) {
                    $findings[] = [$token->line, $site, $finding];
                }
            }

            if ($index === ($attributeOwner[1] ?? null)) {
                $attributeOwner = null;
            }
        }

        return $findings;
    }

    /**
     * For an attribute before a class, trait or enum declaration (code that
     * runs for that class, outside its braces): the class name and the index
     * of the last attribute token. Null for any other attribute.
     *
     * @param  list<PhpToken>  $tokens
     * @return array{string, int}|null
     */
    private function attributeOwner(array $tokens, int $attributeIndex, string $namespace): ?array
    {
        $nesting = 0;
        $attributeEnd = null;

        foreach (array_slice($tokens, $attributeIndex, preserve_keys: true) as $index => $token) {
            if ($token->is(T_ATTRIBUTE) || $token->text === '[') {
                $nesting++;
            } elseif ($token->text === ']') {
                $nesting--;
                $attributeEnd = $nesting === 0 ? $index : $attributeEnd;
            } elseif ($nesting === 0 && ! $token->is([T_FINAL, T_ABSTRACT, T_READONLY])) {
                return $token->is([T_CLASS, T_TRAIT, T_ENUM]) && $attributeEnd !== null
                    ? [ltrim($namespace.'\\'.($tokens[$index + 1] ?? $token)->text, '\\'), $attributeEnd]
                    : null;
            }
        }

        return null;
    }

    /**
     * Trait method aliases of watched methods in one file: `use T {
     * hasPermission as checkPermission; T::can as protected mayDo; }`.
     *
     * @param  list<PhpToken>  $tokens
     * @return array<string, string> lower-cased alias => lower-cased watched name
     */
    private function readMethodAliases(array $tokens): array
    {
        $aliases = [];

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_AS) || ! ($tokens[$index - 1] ?? $token)->is(T_STRING)) {
                continue;
            }

            $originalName = strtolower($tokens[$index - 1]->text);
            $aliasToken = ($tokens[$index + 1] ?? $token)->is([T_PUBLIC, T_PROTECTED, T_PRIVATE]) ? ($tokens[$index + 2] ?? $token) : ($tokens[$index + 1] ?? $token);
            $isWatched = $this->isPolicyAbilityMethod($originalName)
                || $this->isSuperAdminPredicate($originalName)
                || $this->isRolePredicate($originalName)
                || $this->judgeDirectCheck($originalName, false) === null;

            if ($isWatched && $aliasToken->is(T_STRING)) {
                $aliases[strtolower($aliasToken->text)] = $originalName;
            }
        }

        return $aliases;
    }

    /**
     * Verdict on a token that may be a call: false when it does not matter,
     * null for a direct check, or the violation message for a Policy.
     *
     * @param  list<PhpToken>  $tokens
     * @param  array<string, string>  $imports
     */
    private function judgeToken(array $tokens, int $index, bool $isPolicy, string $namespace, array $imports): string|false|null
    {
        $token = $tokens[$index];

        if ($token->is(T_CONSTANT_ENCAPSED_STRING)) {
            // A watched method name assigned to a variable, used as a braced
            // method name (`->{'name'}`) or passed after an object
            // (`[$object, 'name']`, `fn($object, 'name')`) is a call built at
            // runtime; anywhere else it is plain data.
            $context = ($tokens[$index - 1] ?? $token)->text.' '.($tokens[$index + 1] ?? $token)->text;
            $isCallableName = str_starts_with($context, '= ') || in_array($context, ['{ }', ', ]', ', )'], true);

            if (! $isCallableName) {
                return false;
            }

            $methodName = strtolower(substr($token->text, 1, -1));
            $methodName = $this->methodAliases[$methodName] ?? $methodName;

            if ($isPolicy && ($this->isSuperAdminPredicate($methodName) || $this->isRolePredicate($methodName))) {
                return "'{$methodName}' as a callable in a Policy cannot verify super-admin authority; use isActorSuperAdmin() (AC9).";
            }

            return $this->isPolicyAbilityMethod($methodName) && $isPolicy
                ? "'{$methodName}' as a callable in a Policy cannot be verified statically (AC9)."
                : $this->judgeDirectCheck($methodName, $isPolicy);
        }

        if (! $token->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON])) {
            return false;
        }

        $nameIndex = $index + 1;
        $nameToken = $tokens[$nameIndex] ?? $token;
        $openIndex = ($nameToken->text === '{' ? $this->closingBraceIndex($tokens, $nameIndex) : $nameIndex) + 1;

        if (($tokens[$openIndex] ?? null)?->text !== '(') {
            return false;
        }

        if (! $nameToken->is(T_STRING)) {
            return $isPolicy ? 'a dynamically named method call in a Policy cannot be verified statically (AC9).' : false;
        }

        $methodName = strtolower($nameToken->text);
        $methodName = $this->methodAliases[$methodName] ?? $methodName;

        $isRoleOnlySuperAdminCheck = $isPolicy && (
            $this->isSuperAdminPredicate($methodName)
            || ($this->isRolePredicate($methodName) && $this->hasSuperAdminRoleArgument($this->argumentTokens($tokens, $openIndex, shouldReadAll: true)))
        );

        if ($isRoleOnlySuperAdminCheck) {
            return "{$methodName}() with role-only super-admin authority in a Policy; use isActorSuperAdmin() or isProtectedFrom() (AC9).";
        }

        if ($isPolicy && $token->is(T_DOUBLE_COLON) && $this->isGateFacade($tokens[$index - 1] ?? $token, $namespace, $imports)
            && ! $this->isPolicyAbilityMethod($methodName) && ! in_array($methodName, ['has', 'foruser', 'getpolicyfor'], true)) {
            return "Gate::{$methodName}() is not a known Gate method, so a macro cannot be verified statically in a Policy (AC9).";
        }

        if (! $this->isPolicyAbilityMethod($methodName) || ! $isPolicy) {
            return $this->judgeDirectCheck($methodName, $isPolicy);
        }

        $argument = $this->argumentTokens($tokens, $openIndex);

        foreach ($argument as $argumentIndex => $argumentToken) {
            if (($argument[$argumentIndex + 1] ?? null)?->text === '::'
                && strtolower($this->resolveClassName($argumentToken, $namespace, $imports)) === $this->permissionEnumClass) {
                return "{$methodName}() with the permission enum in a Policy; check permissions through the resolver (R5, AC9).";
            }
        }

        if (count($argument) !== 1 || ! $argument[0]->is(T_CONSTANT_ENCAPSED_STRING)) {
            return "{$methodName}() in a Policy with an ability that cannot be verified statically (AC9).";
        }

        return in_array(substr($argument[0]->text, 1, -1), $this->permissionNames, true)
            ? "{$methodName}() with a permission name in a Policy; check permissions through the resolver (R5, AC9)."
            : false;
    }

    private function judgeDirectCheck(string $methodName, bool $isPolicy): ?false
    {
        return in_array($methodName, ['haspermission', 'hasassignedpermission'], true) && ! $isPolicy ? null : false;
    }

    /**
     * @param  array<string, string>  $imports
     */
    private function isGateFacade(PhpToken $classToken, string $namespace, array $imports): bool
    {
        // `\Gate` (or `Gate` in the global namespace) is Laravel's facade alias.
        return in_array(strtolower($this->resolveClassName($classToken, $namespace, $imports)), ['illuminate\\support\\facades\\gate', 'gate'], true);
    }

    private function isPolicyAbilityMethod(string $methodName): bool
    {
        return in_array($methodName, ['can', 'cannot', 'cant', 'canany', 'allows', 'denies', 'authorize', 'inspect'], true);
    }

    private function isSuperAdminPredicate(string $methodName): bool
    {
        return in_array($methodName, ['issuperadmin', 'isdirectsuperadmin'], true);
    }

    private function isRolePredicate(string $methodName): bool
    {
        return in_array($methodName, ['hasrole', 'hasanyrole', 'hasallroles', 'hasexactroles'], true);
    }

    /** @param list<PhpToken> $arguments */
    private function hasSuperAdminRoleArgument(array $arguments): bool
    {
        if (count($arguments) === 1 && $arguments[0]->is(T_ELLIPSIS)) {
            return true;
        }

        foreach ($arguments as $index => $token) {
            if ($token->is(T_CONSTANT_ENCAPSED_STRING) && in_array('super-admin', explode('|', substr($token->text, 1, -1)), true)) {
                return true;
            }

            if ($token->is(T_STRING) && ($arguments[$index - 1] ?? null)?->text === '::' && strtolower($token->text) === 'superadmin') {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads `use A\B, C\D as E;` and `use A\{B, C as D};` at file level.
     * Function and constant imports are skipped.
     *
     * @param  list<PhpToken>  $tokens
     * @return array<string, string> lower-cased alias => class name
     */
    private function readImports(array $tokens, int $useIndex): array
    {
        if (($tokens[$useIndex + 1] ?? $tokens[$useIndex])->is([T_FUNCTION, T_CONST])) {
            return [];
        }

        $imports = [];
        $prefix = '';
        /** @var list<string> $clause the name and, after `as`, the alias of the current import */
        $clause = [];

        foreach (array_slice($tokens, $useIndex) as $token) {
            if ($token->text === '{') {
                $prefix = implode('', $clause).'\\';
                $clause = [];
            } elseif ($token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED])) {
                $clause[] = ltrim($token->text, '\\');
            } elseif ($clause !== [] && in_array($token->text, [',', '}', ';'], true)) {
                $aliasSegments = explode('\\', $clause[array_key_last($clause)]);
                $imports[strtolower($aliasSegments[array_key_last($aliasSegments)])] = $prefix.$clause[0];
                $clause = [];
            }

            if ($token->text === ';') {
                break;
            }
        }

        return $imports;
    }

    /**
     * Read call arguments after `(` at $openIndex. The default returns only
     * the first argument, without a named-argument label.
     *
     * @param  list<PhpToken>  $tokens
     * @return list<PhpToken>
     */
    private function argumentTokens(array $tokens, int $openIndex, bool $shouldReadAll = false): array
    {
        $argument = [];
        $nesting = 0;

        foreach (array_slice($tokens, $openIndex + 1) as $token) {
            if (in_array($token->text, ['(', '[', '{'], true) || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $nesting++;
            } elseif (in_array($token->text, [')', ']', '}'], true) && $nesting-- === 0) {
                break;
            } elseif ($token->text === ',' && $nesting === 0 && ! $shouldReadAll) {
                break;
            }

            $argument[] = $token;
        }

        return ! $shouldReadAll && ($argument[1]->text ?? '') === ':' ? array_slice($argument, 2) : $argument;
    }

    /**
     * @param  list<PhpToken>  $tokens
     * @return int the index of the `}` closing the `{` at $openIndex, or the token count
     */
    private function closingBraceIndex(array $tokens, int $openIndex): int
    {
        $nesting = 0;

        foreach (array_slice($tokens, $openIndex, preserve_keys: true) as $index => $token) {
            if ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $nesting++;
            } elseif ($token->text === '}' && --$nesting === 0) {
                return $index;
            }
        }

        return count($tokens);
    }

    /**
     * @param  array<string, string>  $imports
     */
    private function resolveClassName(PhpToken $nameToken, string $namespace, array $imports): string
    {
        if ($nameToken->is(T_NAME_FULLY_QUALIFIED)) {
            return ltrim($nameToken->text, '\\');
        }

        $name = $nameToken->is(T_NAME_RELATIVE) ? substr($nameToken->text, strlen('namespace\\')) : $nameToken->text;
        $segments = explode('\\', $name);
        $importedName = $nameToken->is(T_NAME_RELATIVE) ? null : ($imports[strtolower($segments[0])] ?? null);

        if ($importedName !== null) {
            $segments[0] = $importedName;

            return implode('\\', $segments);
        }

        return ltrim("{$namespace}\\{$name}", '\\');
    }

    /**
     * The site of the current token: the outermost named class and its
     * method (closures and anonymous classes report their enclosing method),
     * or the file path outside classes; and whether that class is a Policy.
     *
     * @param  array<int, array{kind: string, name: string}>  $scopes
     * @return array{string, bool}
     */
    private function site(array $scopes, string $relativePath): array
    {
        $className = null;
        $methodName = null;

        foreach ($scopes as $scope) {
            if ($className === null && $scope['kind'] === 'class') {
                $className = $scope['name'];
            } elseif ($className !== null && $methodName === null && $scope['kind'] === 'function') {
                $methodName = $scope['name'];
            }
        }

        if ($className === null) {
            return [$relativePath, false];
        }

        return [$methodName === null ? $className : "{$className}::{$methodName}", $this->isPolicyClass($className)];
    }

    private function isPolicyClass(string $className): bool
    {
        return str_ends_with($className, 'Policy') || str_contains("\\{$className}\\", '\\Policies\\');
    }
}
