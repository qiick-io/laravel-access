# Contributing to laravel-access

Thanks for helping. A few rules keep the codebase consistent:

1. Open or pick an issue first. One cohesive area per pull request.
2. Branch from `develop` as `<type>/<kebab-slug>` (types: feat, fix, refactor,
   chore, docs, test, perf, ci, build, security).
3. Use Conventional Commits; PR titles follow the same format. Body lines ≤ 100
   characters.
4. Open the PR against `develop`. PRs are squash-merged. Never push to
   `develop` or `main` directly; never tag from a contributor branch; never
   touch Packagist.
5. Before pushing run `composer test` (Pest), `composer lint` (Pint) and
   `composer analyse` (PHPStan level max). Security-critical classes also need
   `pest --mutate` coverage (design §11 mutation targets; per-class MSI ≥ 95%).
6. Red-first: every security-checklist item becomes a failing test first.
   Tests are deterministic (fakes for every contract; no network or `.env`).
   Middleware that speaks HTTP is exercised through a local `php -S` Testbench
   app started without a shell wrapper and killed in `tearDown` (Laravel 12 and
   13). The CI http-server job fails if that Pest group is empty.
7. Code style: strict types, `final` by default, English docblocks that explain
   *why*, no global helpers, no `Illuminate\Foundation\` or `App\` in `src/`.
   Only `@api` types are SemVer BC; Arch tests guard accidental public-surface
   growth.
8. Security checks are never weakened. Changes to `Gate::before` order, actor
   detection, group soft-delete filters, team cache keys, 2FA exemptions, or
   boot binding checks need a test that proves the bad path is refused.
9. Do not commit `composer.lock`, secrets, or `graphify-out/`.
10. Releases and Packagist: maintainer only (see SECURITY.md and the qiick
    release standard).
