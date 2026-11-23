<?php

declare(strict_types=1);

use Qiick\Access\Testing\DirectPermissionCheckScanner;
use Qiick\Access\Tests\Support\FakeIntPermissionEnum;
use Qiick\Access\Tests\Support\FakePermissionEnum;
use Qiick\Access\Tests\Support\FakeUnitPermissionEnum;

/*
 * AC9 static scan: no `->can(<PermissionEnum>)` (or any Gate ability call with
 * a permission) inside Policies, and no direct `hasPermission()` outside
 * Policies unless qiick-standards.json lists the site in access.direct_checks.
 */

/**
 * Writes a throwaway product tree and returns its root.
 *
 * @param  array<string, string>  $files  relative path => PHP source
 * @param  list<array<string, string>>|null  $directChecks  null writes no access key
 */
function productTree(array $files, ?array $directChecks = []): string
{
    $root = sys_get_temp_dir().'/qiick-access-scan-'.bin2hex(random_bytes(6));
    @mkdir("{$root}/app", 0777, true);
    @mkdir("{$root}/routes", 0777, true);

    foreach ($files as $relativePath => $source) {
        $path = "{$root}/{$relativePath}";
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $source);
    }

    $standards = ['ref' => 'v0.12.0'];

    if ($directChecks !== null) {
        $standards['access'] = ['direct_checks' => $directChecks];
    }

    file_put_contents("{$root}/qiick-standards.json", json_encode($standards));

    return $root;
}

/**
 * @param  array<string, string>  $files
 * @param  list<array<string, string>>|null  $directChecks
 * @return list<string>
 */
function scanProduct(array $files, ?array $directChecks = [], string $today = '2026-11-23'): array
{
    $root = productTree($files, $directChecks);
    $scanner = new DirectPermissionCheckScanner(FakePermissionEnum::class, new DateTimeImmutable($today));

    return $scanner->scan($root, ['app', 'routes'], "{$root}/qiick-standards.json");
}

function listedSite(string $site, string $reviewBy = '2027-03-01'): array
{
    return ['site' => $site, 'reason' => 'tool inventory before a model exists', 'decision' => 'knowledge/decisions/x.md', 'review_by' => $reviewBy];
}

const POLICY_HEADER = <<<'PHP'
<?php

namespace App\Policies;

use Qiick\Access\Tests\Support\FakePermissionEnum;

PHP;

it('accepts a clean product', function (): void {
    expect(scanProduct([
        'app/Policies/PostPolicy.php' => POLICY_HEADER.<<<'PHP'
            final class PostPolicy
            {
                public function update($actor, $post): bool
                {
                    return $this->permissions->hasPermission($actor, FakePermissionEnum::CanEditUsers->value)
                        && $actor->can('update-own', $post);
                }
            }
            PHP,
    ]))->toBe([]);
});

it('flags ->can() with the permission enum inside a Policy, however the enum is named', function (string $import, string $reference): void {
    $violations = scanProduct([
        'app/Policies/PostPolicy.php' => "<?php\n\nnamespace App\\Policies;\n\n{$import}\n\nfinal class PostPolicy\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can({$reference});\n    }\n}\n",
    ]);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toStartWith('app/Policies/PostPolicy.php:11 ')
        ->and($violations[0])->toContain('App\Policies\PostPolicy::view')
        ->and($violations[0])->toContain('permission enum');
})->with([
    'imported' => ['use Qiick\Access\Tests\Support\FakePermissionEnum;', 'FakePermissionEnum::CanShowUsers->value'],
    'aliased' => ['use Qiick\Access\Tests\Support\FakePermissionEnum as Perm;', 'Perm::CanShowUsers'],
    'group import' => ['use Qiick\Access\Tests\Support\{FakeRoleCatalog, FakePermissionEnum as P};', 'P::CanShowUsers->value'],
    'namespace import' => ['use Qiick\Access\Tests\Support;', 'Support\FakePermissionEnum::CanShowUsers->value'],
    'fully qualified' => ['', '\Qiick\Access\Tests\Support\FakePermissionEnum::CanShowUsers->value'],
    'case-insensitive' => ['use Qiick\Access\Tests\Support\FAKEPERMISSIONENUM;', 'fakepermissionenum::CanShowUsers->value'],
]);

it('flags every Gate ability method and permission string literals inside a Policy', function (string $call): void {
    $violations = scanProduct([
        'app/Policies/PostPolicy.php' => POLICY_HEADER."final class PostPolicy\n{\n    public function view(\$actor): bool\n    {\n        return {$call};\n    }\n}\n",
    ]);

    expect($violations)->toHaveCount(1);
})->with([
    'can with a permission literal' => ["\$actor->can('can-show-users')"],
    'double-quoted literal' => ['$actor->can("can-show-users")'],
    'cannot' => ['$actor->cannot(FakePermissionEnum::CanShowUsers)'],
    'cant' => ['$actor->cant(FakePermissionEnum::CanShowUsers)'],
    'canAny' => ['$actor->canAny([FakePermissionEnum::CanShowUsers])'],
    'nullsafe' => ['$actor?->can(FakePermissionEnum::CanShowUsers)'],
    'Gate allows' => ['\Illuminate\Support\Facades\Gate::allows(FakePermissionEnum::CanShowUsers)'],
    'Gate denies' => ['$this->gate->denies(FakePermissionEnum::CanShowUsers)'],
    'Gate authorize' => ['Gate::authorize(FakePermissionEnum::CanShowUsers->value)'],
    'Gate inspect' => ['Gate::inspect(FakePermissionEnum::CanShowUsers->value)->allowed()'],
    'named argument' => ['$actor->can(abilities: FakePermissionEnum::CanShowUsers)'],
    'upper-case method' => ['$actor->CAN(FakePermissionEnum::CanShowUsers)'],
]);

it('flags Policy ability calls whose argument cannot be verified statically', function (string $call): void {
    $violations = scanProduct([
        'app/Policies/PostPolicy.php' => POLICY_HEADER."final class PostPolicy\n{\n    public function view(\$actor, \$ability): bool\n    {\n        return {$call};\n    }\n}\n",
    ]);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('cannot be verified');
})->with([
    'variable ability' => ['$actor->can($ability)'],
    'constant ability' => ['$actor->can(self::ABILITY)'],
    'spread arguments' => ['$actor->can(...$this->arguments)'],
    'first-class callable' => ['($actor->can(...))(\'x\')'],
    'no arguments' => ['$actor->can()'],
    'interpolated string' => ['$actor->can("can-{$ability}")'],
    'concatenated string' => ["\$actor->can('can-' . 'show-users')"],
]);

it('flags dynamically built calls inside a Policy', function (string $call): void {
    $violations = scanProduct([
        'app/Policies/PostPolicy.php' => POLICY_HEADER."final class PostPolicy\n{\n    public function view(\$actor, \$method): bool\n    {\n        return {$call};\n    }\n}\n",
    ]);

    expect($violations)->not->toBe([]);
})->with([
    'variable method name' => ['$actor->$method(FakePermissionEnum::CanShowUsers)'],
    'braced literal method name' => ["\$actor->{'can'}(FakePermissionEnum::CanShowUsers)"],
    'braced expression method name' => ["\$actor->{\$method . 'x'}('anything')"],
    'array callable' => ["call_user_func([\$actor, 'can'], 'can-show-users')"],
    'string callable variable' => ["(function () use (\$actor) { \$name = 'can'; return \$actor->\$name('x'); })()"],
    'static variable method' => ['Gate::$method(\'x\')'],
]);

it('ignores calls inside comments, docblocks and strings', function (): void {
    expect(scanProduct([
        'app/Policies/PostPolicy.php' => POLICY_HEADER.<<<'PHP'
            /**
             * Never call $actor->can(FakePermissionEnum::CanShowUsers) here, nor hasPermission().
             */
            final class PostPolicy
            {
                // $actor->can(FakePermissionEnum::CanShowUsers)
                # $resolver->hasPermission($actor, 'x')
                public function view($actor): bool
                {
                    $help = 'call $actor->can(FakePermissionEnum::CanShowUsers) to check';
                    $more = "the resolver's hasPermission( method";
                    $doc = <<<TXT
                        $actor->can(FakePermissionEnum::CanShowUsers)
                        TXT;

                    return $actor->can('update-own');
                }
            }
            PHP,
        'app/Services/HelpService.php' => <<<'PHP'
            <?php

            namespace App\Services;

            /* $resolver->hasPermission($user, 'x') */
            final class HelpService
            {
                public function text(): string
                {
                    return 'use hasPermission carefully';
                }
            }
            PHP,
    ]))->toBe([]);
});

it('does not apply the Policy rule outside Policies', function (): void {
    expect(scanProduct([
        'app/Http/Controllers/PostController.php' => <<<'PHP'
            <?php

            namespace App\Http\Controllers;

            use Qiick\Access\Tests\Support\FakePermissionEnum;

            final class PostController
            {
                public function index($actor): bool
                {
                    return $actor->can(FakePermissionEnum::CanShowUsers->value);
                }
            }
            PHP,
    ]))->toBe([]);
});

it('treats a class in a Policies namespace or ending in Policy as a Policy', function (string $namespace, string $class): void {
    $violations = scanProduct([
        'app/Authorization/Rules.php' => "<?php\n\nnamespace {$namespace};\n\nfinal class {$class}\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can('can-show-users');\n    }\n}\n",
    ]);

    expect($violations)->toHaveCount(1);
})->with([
    'Policy suffix' => ['App\Authorization', 'DocumentPolicy'],
    'Policies namespace' => ['App\Policies\Documents', 'DocumentRules'],
]);

it('flags an unlisted direct hasPermission() outside Policies with its site', function (string $call): void {
    $violations = scanProduct([
        'app/Ai/Agents/AppAssistant.php' => "<?php\n\nnamespace App\\Ai\\Agents;\n\nfinal class AppAssistant\n{\n    public function tools(\$resolver, \$user, \$name): array\n    {\n        return [{$call}];\n    }\n}\n",
    ]);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toStartWith('app/Ai/Agents/AppAssistant.php:9 ')
        ->and($violations[0])->toContain('App\Ai\Agents\AppAssistant::tools');
})->with([
    'method call' => ["\$resolver->hasPermission(\$user, 'can-show-users')"],
    'assigned permission' => ["\$resolver->hasAssignedPermission(\$user, 'can-show-users', true)"],
    'nullsafe' => ["\$resolver?->hasPermission(\$user, 'x')"],
    'static call' => ["Resolver::hasPermission(\$user, 'x')"],
    'mixed case' => ["\$resolver->HasPermission(\$user, 'x')"],
    'braced name' => ["\$resolver->{'hasPermission'}(\$user, 'x')"],
    'array callable' => ["call_user_func([\$resolver, 'hasPermission'], \$user, 'x')"],
    'string held in a variable' => ["(\$name = 'hasPermission') ? \$resolver->\$name(\$user, 'x') : null"],
    'first-class callable' => ['$resolver->hasPermission(...)'],
]);

it('accepts a listed direct check and flags the same call at an unlisted site', function (): void {
    $source = <<<'PHP'
        <?php

        namespace App\Ai\Agents;

        final class AppAssistant
        {
            public function tools($resolver, $user): bool
            {
                return $resolver->hasPermission($user, 'can-show-users');
            }

            public function prompt($resolver, $user): bool
            {
                return $resolver->hasPermission($user, 'can-show-users');
            }
        }
        PHP;

    $violations = scanProduct(['app/Ai/Agents/AppAssistant.php' => $source], [listedSite('App\Ai\Agents\AppAssistant::tools')]);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('App\Ai\Agents\AppAssistant::prompt');
});

it('matches listed sites case-insensitively and with or without a leading backslash', function (): void {
    $violations = scanProduct([
        'app/Ai/Agents/AppAssistant.php' => "<?php\n\nnamespace App\\Ai\\Agents;\n\nfinal class AppAssistant\n{\n    public function tools(\$resolver, \$user): bool\n    {\n        return \$resolver->hasPermission(\$user, 'x');\n    }\n}\n",
    ], [listedSite('\app\ai\agents\appassistant::TOOLS')]);

    expect($violations)->toBe([]);
});

it('allows direct checks inside Policies and the Inertia share', function (): void {
    expect(scanProduct([
        'app/Http/Middleware/HandleInertiaRequests.php' => <<<'PHP'
            <?php

            namespace App\Http\Middleware;

            final class HandleInertiaRequests
            {
                public function share($request): array
                {
                    return ['can' => $this->permissions->hasPermission($request->user(), 'can-show-users')];
                }
            }
            PHP,
    ]))->toBe([]);
});

it('names closures, anonymous classes and code outside classes by their enclosing site', function (): void {
    $violations = scanProduct([
        'routes/web.php' => <<<'PHP'
            <?php

            use Illuminate\Support\Facades\Route;

            Route::get('/', function () use ($resolver) {
                return $resolver->hasPermission(auth()->user(), 'x');
            });
            PHP,
        'app/Services/ReportService.php' => <<<'PHP'
            <?php

            namespace App\Services;

            final class ReportService
            {
                public function build($resolver, $user): object
                {
                    $filter = fn ($row) => $resolver->hasPermission($user, 'x');

                    return new class($resolver)
                    {
                        public function __construct(private $resolver) {}

                        public function check($user): bool
                        {
                            return $this->resolver->hasPermission($user, 'x');
                        }
                    };
                }
            }
            PHP,
    ]);

    expect($violations)->toHaveCount(3)
        ->and($violations[0])->toContain('App\Services\ReportService::build')
        ->and($violations[1])->toContain('App\Services\ReportService::build')
        ->and($violations[2])->toStartWith('routes/web.php:6 ')
        ->and($violations[2])->toContain('site routes/web.php');
});

it('does not take a method declaration, a property or ::class for a call', function (): void {
    expect(scanProduct([
        'app/Services/AccessService.php' => <<<'PHP'
            <?php

            namespace App\Services;

            interface Checks
            {
                public function hasPermission($user, string $name): bool;
            }

            abstract class AccessService implements Checks
            {
                public bool $hasPermission = false;

                abstract public function can(string $ability): bool;

                public function name(): string
                {
                    return $this->hasPermission ? static::class : self::class;
                }
            }
            PHP,
    ]))->toBe([]);
});

it('flags a listed entry whose site no longer has a direct check', function (): void {
    $violations = scanProduct([], [listedSite('App\Gone::method')]);

    expect($violations)->toBe(['qiick-standards.json access.direct_checks [App\Gone::method]: no direct check at this site any more; remove the entry (the list only shrinks).']);
});

it('flags incomplete or expired direct_checks entries', function (array $entry, string $problem): void {
    $source = "<?php\n\nnamespace App;\n\nfinal class Tool\n{\n    public function run(\$resolver, \$user): bool\n    {\n        return \$resolver->hasPermission(\$user, 'x');\n    }\n}\n";
    $violations = scanProduct(['app/Tool.php' => $source], [$entry]);

    expect(implode("\n", $violations))->toContain($problem);
})->with([
    'no reason' => [['site' => 'App\Tool::run', 'decision' => 'd', 'review_by' => '2027-01-01'], 'needs a reason'],
    'empty decision' => [['site' => 'App\Tool::run', 'reason' => 'r', 'decision' => ' ', 'review_by' => '2027-01-01'], 'needs a decision'],
    'no site' => [['reason' => 'r', 'decision' => 'd', 'review_by' => '2027-01-01'], 'needs a site'],
    'bad date' => [['site' => 'App\Tool::run', 'reason' => 'r', 'decision' => 'd', 'review_by' => '01/01/2027'], 'review_by must be a Y-m-d date'],
    'impossible date' => [['site' => 'App\Tool::run', 'reason' => 'r', 'decision' => 'd', 'review_by' => '2027-02-31'], 'review_by must be a Y-m-d date'],
    'expired' => [['site' => 'App\Tool::run', 'reason' => 'r', 'decision' => 'd', 'review_by' => '2026-11-22'], 'review_by 2026-11-22 has passed'],
]);

it('keeps an entry due today valid', function (): void {
    $source = "<?php\n\nnamespace App;\n\nfinal class Tool\n{\n    public function run(\$resolver, \$user): bool\n    {\n        return \$resolver->hasPermission(\$user, 'x');\n    }\n}\n";

    expect(scanProduct(['app/Tool.php' => $source], [listedSite('App\Tool::run', '2026-11-23')]))->toBe([]);
});

it('treats a missing access key as an empty list and rejects a malformed one', function (): void {
    $source = "<?php\n\nnamespace App;\n\nfinal class Tool\n{\n    public function run(\$resolver, \$user): bool\n    {\n        return \$resolver->hasPermission(\$user, 'x');\n    }\n}\n";

    expect(scanProduct(['app/Tool.php' => $source], null))->toHaveCount(1);

    $root = productTree([]);
    file_put_contents("{$root}/qiick-standards.json", '{"access": {"direct_checks": "nope"}}');
    $scanner = new DirectPermissionCheckScanner(FakePermissionEnum::class, new DateTimeImmutable('2026-11-23'));

    expect(fn () => $scanner->scan($root, ['app'], "{$root}/qiick-standards.json"))
        ->toThrow(InvalidArgumentException::class, 'access.direct_checks must be a list');

    expect(fn () => $scanner->scan($root, ['app'], "{$root}/missing.json"))
        ->toThrow(InvalidArgumentException::class, 'missing.json');

    file_put_contents("{$root}/qiick-standards.json", '{not json');
    expect(fn () => $scanner->scan($root, ['app'], "{$root}/qiick-standards.json"))
        ->toThrow(InvalidArgumentException::class, 'not valid JSON');
});

it('scans single files, skips non-PHP files and missing paths fail loudly', function (): void {
    $root = productTree([
        'app/Tool.php' => "<?php\n\$resolver->hasPermission(\$user, 'x');\n",
        'app/notes.txt' => "\$resolver->hasPermission(\$user, 'x');",
        'app/view.blade.php.bak' => "<?php \$resolver->hasPermission(\$user, 'x');",
    ]);
    $scanner = new DirectPermissionCheckScanner(FakePermissionEnum::class, new DateTimeImmutable('2026-11-23'));

    expect($scanner->scan($root, ['app/Tool.php'], "{$root}/qiick-standards.json"))->toBe([
        'app/Tool.php:2 direct hasPermission() at site app/Tool.php is not listed in qiick-standards.json access.direct_checks (AC9).',
    ])->and($scanner->scan($root, ['app'], "{$root}/qiick-standards.json"))->toHaveCount(1);

    expect(fn () => $scanner->scan($root, ['nowhere'], "{$root}/qiick-standards.json"))
        ->toThrow(InvalidArgumentException::class, 'nowhere');
});

it('rejects a permission enum that is not backed by strings', function (): void {
    expect(fn () => new DirectPermissionCheckScanner(stdClass::class))
        ->toThrow(InvalidArgumentException::class, 'string-backed enum');
});

it('reports violations in a stable order', function (): void {
    $violations = scanProduct([
        'app/B.php' => "<?php\nnamespace App;\nfinal class B { public function run(\$r, \$u) { return \$r->hasPermission(\$u, 'x'); } }\n",
        'app/A.php' => "<?php\nnamespace App;\nfinal class A { public function run(\$r, \$u) { \$r->hasPermission(\$u, 'x'); return \$r->hasPermission(\$u, 'y'); } }\n",
    ]);

    expect($violations)->toHaveCount(3)
        ->and($violations[0])->toStartWith('app/A.php:3 ')
        ->and($violations[2])->toStartWith('app/B.php:3 ');
});

/**
 * @return list<string> violations of one Policy method body
 */
function scanPolicyBody(string $body, string $header = POLICY_HEADER): array
{
    return scanProduct([
        'app/Policies/PostPolicy.php' => $header."final class PostPolicy\n{\n    public function view(\$actor, \$a, \$suffix): bool\n    {\n        {$body}\n    }\n}\n",
    ]);
}

const ENUM_MESSAGE = 'with the permission enum in a Policy';
const UNVERIFIABLE_MESSAGE = 'with an ability that cannot be verified statically';

it('reads calls across whitespace and comments', function (): void {
    expect(scanPolicyBody("return \$actor -> /* note */ can ( // why\n FakePermissionEnum::CanShowUsers );"))
        ->toHaveCount(1);
});

it('reads only the first argument of an ability call', function (string $call, string $message): void {
    $violations = scanPolicyBody("return {$call};");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain($message);
})->with([
    'call holding a comma' => ["\$actor->can(foo('x', FakePermissionEnum::CanShowUsers))", ENUM_MESSAGE],
    'array holding a comma' => ["\$actor->can(['update-own', FakePermissionEnum::CanShowUsers])", ENUM_MESSAGE],
    'match holding a comma' => ["\$actor->can(match (\$a) { 1 => 'x', default => FakePermissionEnum::CanShowUsers->value })", ENUM_MESSAGE],
    'interpolation then the enum' => ['$actor->can("x{$a}", FakePermissionEnum::CanShowUsers)', UNVERIFIABLE_MESSAGE],
    'dollar-brace interpolation then the enum' => ['$actor->can("x${a}", FakePermissionEnum::CanShowUsers)', UNVERIFIABLE_MESSAGE],
    'array then the enum' => ["\$actor->canAny(['update-own'], FakePermissionEnum::CanShowUsers)", UNVERIFIABLE_MESSAGE],
    'call then the enum' => ['$actor->can(foo(), FakePermissionEnum::CanShowUsers)', UNVERIFIABLE_MESSAGE],
    'match then the enum' => ["\$actor->can(match (\$a) { default => 'x' }, FakePermissionEnum::CanShowUsers)", UNVERIFIABLE_MESSAGE],
    'enum name not used as a class' => ['$actor->can($a instanceof FakePermissionEnum)', UNVERIFIABLE_MESSAGE],
    'named permission literal' => ["\$actor->can(ability: 'can-show-users')", 'with a permission name in a Policy'],
]);

it('accepts a named non-permission ability and ignores watched names that are not calls', function (): void {
    expect(scanPolicyBody("\$y = \$actor->\$a;\n\$z = \$actor->can;\nreturn \$actor->can(ability: 'update-own') && \$this->resolver->hasPermission(\$actor, 'can-show-users') && \$actor->{'hasPermission'};"))
        ->toBe([]);
});

it('flags a braced method name holding an interpolation', function (): void {
    $violations = scanPolicyBody("return \$actor->{\"can{\$suffix}\"}('x');");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain('dynamically named method call');
});

it('does not apply the callable-name rule to ability names outside Policies or to direct checks inside them', function (): void {
    expect(scanProduct([
        'app/Http/Controllers/PostController.php' => "<?php\n\nnamespace App\\Http\\Controllers;\n\nfinal class PostController\n{\n    public function index(\$actor): array\n    {\n        return [[\$actor, 'can'], 'allows'];\n    }\n}\n",
    ]))->toBe([])
        ->and(scanPolicyBody("return [\$this->resolver, 'hasPermission'];"))->toBe([]);
});

it('resolves the enum by namespace-relative, same-namespace and global qualified names', function (string $file): void {
    $violations = scanProduct(['app/Policies/PostPolicy.php' => $file]);

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain(ENUM_MESSAGE);
})->with([
    'namespace-relative' => ["<?php\n\nnamespace Qiick\\Access\\Tests;\n\nfinal class PostPolicy\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can(namespace\\Support\\FakePermissionEnum::CanShowUsers);\n    }\n}\n"],
    'same namespace' => ["<?php\n\nnamespace Qiick\\Access\\Tests\\Support;\n\nfinal class PostPolicy\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can(FakePermissionEnum::CanShowUsers);\n    }\n}\n"],
    'global namespace, qualified' => ["<?php\n\nfinal class PostPolicy\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can(Qiick\\Access\\Tests\\Support\\FakePermissionEnum::CanShowUsers);\n    }\n}\n"],
]);

it('does not resolve a namespace-relative name through an import', function (): void {
    expect(scanProduct(['app/Policies/PostPolicy.php' => "<?php\n\nnamespace App\\Policies;\n\nuse Qiick\\Access\\Tests\\Support as Support;\n\nfinal class PostPolicy\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can(namespace\\Support\\FakePermissionEnum::CanShowUsers);\n    }\n}\n"])[0])
        ->toContain(UNVERIFIABLE_MESSAGE);
});

it('reads every import form', function (string $imports, string $reference): void {
    $violations = scanPolicyBody("return \$actor->can({$reference});", "<?php\n\nnamespace App\\Policies;\n\n{$imports}\n\n");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain(ENUM_MESSAGE);
})->with([
    'earlier import kept' => ["use Qiick\\Access\\Tests\\Support\\FakePermissionEnum;\nuse App\\Models\\Post;", 'FakePermissionEnum::CanShowUsers'],
    'comma-separated imports' => ['use App\Models\Post, Qiick\Access\Tests\Support\FakePermissionEnum;', 'FakePermissionEnum::CanShowUsers'],
    'comma-separated aliases' => ['use App\Models\Post as P, Qiick\Access\Tests\Support\FakePermissionEnum as E;', 'E::CanShowUsers'],
    'leading backslash' => ['use \Qiick\Access\Tests\Support\FakePermissionEnum;', 'FakePermissionEnum::CanShowUsers'],
    'function import ignored' => ["use Qiick\\Access\\Tests\\Support\\FakePermissionEnum;\nuse function App\\FakePermissionEnum;", 'FakePermissionEnum::CanShowUsers'],
    'constant import ignored' => ["use Qiick\\Access\\Tests\\Support\\FakePermissionEnum;\nuse const App\\FakePermissionEnum;", 'FakePermissionEnum::CanShowUsers'],
]);

it('treats traits and enums in a Policies namespace as Policies, and classes by name', function (string $file, int $expectedCount): void {
    expect(scanProduct(['app/Policies/Unit.php' => $file]))->toHaveCount($expectedCount);
})->with([
    'trait' => ["<?php\nnamespace App\\Policies\\Concerns;\ntrait ChecksPosts\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can('can-show-users');\n    }\n}\n", 1],
    'enum' => ["<?php\nnamespace App\\Policies;\nenum Ability: string\n{\n    case View = 'view';\n    public function allowed(\$actor): bool\n    {\n        return \$actor->can('can-show-users');\n    }\n}\n", 1],
    'root Policies namespace' => ["<?php\nnamespace Policies;\nfinal class Rules\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can('can-show-users');\n    }\n}\n", 1],
    'class named Policies' => ["<?php\nnamespace App;\nfinal class Policies\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can('can-show-users');\n    }\n}\n", 1],
    'look-alike namespace' => ["<?php\nnamespace App\\PoliciesHelper;\nfinal class Rules\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can('can-show-users');\n    }\n}\n", 0],
    'look-alike class' => ["<?php\nnamespace App;\nfinal class PolicyHelper\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can('can-show-users');\n    }\n}\n", 0],
]);

it('tracks sites through functions, braces, strings and declarations', function (): void {
    $source = <<<'PHP'
        <?php

        function helper($resolver, $user)
        {
            return $resolver->hasPermission($user, 'x');
        }

        final class Tool
        {
            abstract public function declared(): void;

            public function &tools($resolver, $user, $a)
            {
                $label = "x{$a} y${a}";

                if ($a === Tool::class) {
                    $label = static::class;
                }

                foreach ([1] as $one) {
                    $resolver->hasPermission($user, 'x');
                }

                return $resolver->hasPermission($user, 'x');
            }

            public function other($resolver, $user)
            {
                return $resolver->hasPermission($user, 'x');
            }
        }
        PHP;

    expect(scanProduct(['app/Tool.php' => $source], [listedSite('Tool::tools')]))->toBe([
        'app/Tool.php:5 direct hasPermission() at site app/Tool.php is not listed in qiick-standards.json access.direct_checks (AC9).',
        'app/Tool.php:29 direct hasPermission() at site Tool::other is not listed in qiick-standards.json access.direct_checks (AC9).',
    ]);
});

it('names a global class declared first in the file', function (): void {
    expect(scanProduct(['app/Tool.php' => "<?php class Tool { function run(\$r, \$u) { return \$r->hasPermission(\$u, 'x'); } }"]))
        ->toBe(['app/Tool.php:1 direct hasPermission() at site Tool::run is not listed in qiick-standards.json access.direct_checks (AC9).']);
});

it('survives truncated files', function (): void {
    expect(scanProduct([
        'app/A.php' => '<?php namespace',
        'app/B.php' => '<?php $a->',
        'app/C.php' => '<?php function',
        'app/D.php' => '<?php class',
        'app/E.php' => '<?php $a->{',
        'app/F.php' => '<?php use',
        'app/G.php' => '<?php $a->{"x"',
        'app/H.php' => '<?php function &',
    ]))->toBe([]);
});

it('only allows the Inertia share method of a HandleInertiaRequests class', function (string $class, string $method, int $expectedCount): void {
    expect(scanProduct(['app/Share.php' => "<?php\nfinal class {$class}\n{\n    public function {$method}(\$r, \$u)\n    {\n        return \$r->hasPermission(\$u, 'x');\n    }\n}\n"]))
        ->toHaveCount($expectedCount);
})->with([
    'global namespace share' => ['HandleInertiaRequests', 'share', 0],
    'look-alike class' => ['NotHandleInertiaRequests', 'share', 1],
    'other method' => ['HandleInertiaRequests', 'version', 1],
]);

it('reports exact messages for invalid direct_checks entries and keeps reading after one', function (): void {
    $source = "<?php\n\nnamespace App;\n\nfinal class Tool\n{\n    public function run(\$resolver, \$user): bool\n    {\n        return \$resolver->hasPermission(\$user, 'x');\n    }\n}\n";

    expect(scanProduct(['app/Tool.php' => $source], [
        ['reason' => 'r', 'decision' => 'd'],
        'not an entry',
        ['site' => 'App\Gone::run', 'reason' => 'r', 'decision' => 'd', 'review_by' => '2026-11-22'],
        listedSite('App\Tool::run'),
    ]))->toBe([
        'qiick-standards.json access.direct_checks[0]: needs a site; review_by must be a Y-m-d date.',
        'qiick-standards.json access.direct_checks[1]: needs a site; needs a reason; needs a decision; review_by must be a Y-m-d date.',
        'qiick-standards.json access.direct_checks[2] [App\Gone::run]: review_by 2026-11-22 has passed; review the exception or remove the call.',
    ]);
});

it('uses the real date by default and an injected date otherwise', function (): void {
    $source = "<?php\n\nnamespace App;\n\nfinal class Tool\n{\n    public function run(\$resolver, \$user): bool\n    {\n        return \$resolver->hasPermission(\$user, 'x');\n    }\n}\n";
    $root = productTree(['app/Tool.php' => $source], [listedSite('App\Tool::run', '2999-01-01')]);

    expect((new DirectPermissionCheckScanner(FakePermissionEnum::class))->scan($root, ['app'], "{$root}/qiick-standards.json"))->toBe([])
        ->and((new DirectPermissionCheckScanner(FakePermissionEnum::class, new DateTimeImmutable('3000-01-01')))->scan($root, ['app'], "{$root}/qiick-standards.json"))->toHaveCount(2);

    $expiredRoot = productTree(['app/Tool.php' => $source], [listedSite('App\Tool::run', '2001-01-01')]);

    expect((new DirectPermissionCheckScanner(FakePermissionEnum::class))->scan($expiredRoot, ['app'], "{$expiredRoot}/qiick-standards.json"))->toHaveCount(2);
});

it('keeps path order, trims slashes and accepts a leading-backslash enum class', function (): void {
    $root = productTree([
        'app/Tool.php' => "<?php\n\$r->hasPermission(\$u, 'x');\n",
        'routes/web.php' => "<?php\n\$r->hasPermission(\$u, 'x');\n",
    ]);
    $scanner = new DirectPermissionCheckScanner('\\'.FakePermissionEnum::class, new DateTimeImmutable('2026-11-23'));

    expect($scanner->scan("{$root}/", ['/routes/', 'app/Tool.php/'], "{$root}/qiick-standards.json"))->toBe([
        'routes/web.php:2 direct hasPermission() at site routes/web.php is not listed in qiick-standards.json access.direct_checks (AC9).',
        'app/Tool.php:2 direct hasPermission() at site app/Tool.php is not listed in qiick-standards.json access.direct_checks (AC9).',
    ]);
});

it('rejects an access key that is not an object and enums that are not string-backed', function (): void {
    $root = productTree([]);
    file_put_contents("{$root}/qiick-standards.json", '{"access": "nope"}');

    expect(fn () => (new DirectPermissionCheckScanner(FakePermissionEnum::class))->scan($root, ['app'], "{$root}/qiick-standards.json"))
        ->toThrow(InvalidArgumentException::class, 'must be a list')
        ->and(fn () => new DirectPermissionCheckScanner(FakeIntPermissionEnum::class))
        ->toThrow(InvalidArgumentException::class, 'string-backed enum')
        ->and(fn () => new DirectPermissionCheckScanner(FakeUnitPermissionEnum::class))
        ->toThrow(InvalidArgumentException::class, 'string-backed enum');
});

it('keeps file-level code at the file site around anonymous classes and ::class', function (): void {
    $source = <<<'PHP'
        <?php

        $helper = new class
        {
            public function check($r, $u)
            {
                return $r->hasPermission($u, 'x');
            }
        };

        if ($x === Foo::class) {
            $r->hasPermission($u, 'x');
        }
        PHP;

    expect(scanProduct(['routes/web.php' => $source], [listedSite('routes/web.php')]))->toBe([]);
});

it('keeps reading an argument past an interpolation', function (string $argument): void {
    $violations = scanPolicyBody("return \$actor->can({$argument});");

    expect($violations)->toHaveCount(1)
        ->and($violations[0])->toContain(ENUM_MESSAGE);
})->with([
    'curly interpolation' => ['"x{$a}" . FakePermissionEnum::CanShowUsers->value'],
    'dollar-brace interpolation' => ['"x${a}" . FakePermissionEnum::CanShowUsers->value'],
]);

it('finds the end of a braced method name holding a dollar-brace interpolation', function (): void {
    expect(scanPolicyBody("return \$actor->{\"can\${suffix}\"}('x');"))->toHaveCount(1);
});

it('matches the enum when its class is given with a leading backslash', function (): void {
    $root = productTree(['app/Policies/PostPolicy.php' => POLICY_HEADER."final class PostPolicy\n{\n    public function view(\$actor): bool\n    {\n        return \$actor->can(FakePermissionEnum::CanShowUsers);\n    }\n}\n"]);
    $scanner = new DirectPermissionCheckScanner('\\'.FakePermissionEnum::class, new DateTimeImmutable('2026-11-23'));

    expect($scanner->scan($root, ['app'], "{$root}/qiick-standards.json")[0])->toContain(ENUM_MESSAGE);
});
