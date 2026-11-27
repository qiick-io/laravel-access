<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset=".github/assets/header-dark.svg">
    <source media="(prefers-color-scheme: light)" srcset=".github/assets/header-light.svg">
    <img alt="laravel-access: Authorization core for Laravel products" src=".github/assets/header-light.svg" width="100%">
  </picture>
</p>

# qiick/laravel-access

> **Pre-1.0 (`0.x`): public APIs may change in any minor. Not on Packagist yet.**

Shared authorization core for qiick Laravel products: one `Gate::before`, the
effective-permission resolver (direct ∪ group roles, soft-deleted groups
excluded), a typed actor context so API actors never get the super-admin pass,
enforced 2FA middleware, the `EnsureUserHasPermission` route middleware and the
`AuthorizesWithPermission` FormRequest trait.

Part of the qiick fleet. Binding design:
`projects/qiick/packages/laravel-access-design.md` in the knowledge base
(overlays: `decisions/2026-11-22-laravel-access-package.md`,
`decisions/2026-11-22-mcp-oauth-and-access-design-answers.md`,
`decisions/2026-11-20-core-hardening.md`).

## Status

`0.1.0-beta.3` is the current tagged pre-release (the first was
`0.1.0-beta.1`), for beta adoption in the qiick products. Public APIs may change in any 0.x release; see
[UPGRADING.md](./UPGRADING.md). Packagist publishing waits for the
maintainer's go; until then products install it from the tag.

## Requirements

- PHP `^8.3`
- Illuminate auth / contracts / database / http / support `^12.0 \|\| ^13.0`
  (never `laravel/framework`)
- `spatie/laravel-permission` `^8.0`

## Install (when published)

```bash
composer require qiick/laravel-access
php artisan vendor:publish --tag=access-config
```

Products must bind `RoleCatalog`, `TwoFactorPolicy`, `TwoFactorResponder` and
`AuthorizationResponder`, set `permission.register_permission_check_method` to
`false`, and call `AccessContext::enter()` after verifying a credential. See
[docs/adoption.md](./docs/adoption.md) and
[docs/reference-contracts.md](./docs/reference-contracts.md).

## Threat model (short)

| Threat | Mitigation |
| --- | --- |
| Super-admin elevation through a key, grant or public door | `Gate::before` step 2 abstains for API actors; Spatie's permission callback off; AC2 |
| Future model-less check on an API path | Step 2 unconditional; `permission:` middleware and trait give API actors only scopes ∩ the owner ceiling (assigned permissions, the vocabulary for a super-admin owner), never the Gate bypass, and deny them when no `ApiActorScopeCheck` is bound; Policies use the resolver, never `$actor->can(<permission>)`; AC2, AC8, AC9 |
| Forged actor kind | Context bound only after verification; resolver never reads request input; either-signal rule; AC4 |
| Job escaping the key's limits | `CarriesActorContext` job middleware; AC5 |
| Stale permissions after demotion | `fresh` use-time checks, event and lifecycle clears, scoped resolver; AC6, AC11 |
| Soft-deleted group still grants | SQL `whereNull(deleted_at)`; C7 |
| Organisation bleed | Team id in the cache key; `switchTo()` clears; C29 |
| A grant changed in the same request or job still counting, or a new one not counting yet | The resolver caches per scope; it is cleared between requests, jobs and Octane events, by `TeamContext::switchTo()`, by fresh checks, and for one user by Spatie's role and permission attach and detach events on that user. Nothing else clears it: after writing `user_group_user` or `user_group_role`, soft-deleting or restoring a group, or changing a role's permissions, the product calls `EffectivePermissionResolver::forget($user)` for each member it changed or `flush()` for a group- or role-wide change before checking again in the same request or job; a membership that expires mid-job counts until then. Under Octane the Gate, these clears and `switchTo()` use the request's own container (#69) |
| Spatie's team filter seen off by another request | The resolver turns `PermissionRegistrar::$teams` off while it builds one `roles()` relation and restores it in `finally`. On the standard Spatie Eloquent path that window runs no query and cannot yield; one process serves one request or job at a time (PHP-FPM, queue workers, Octane on Swoole, RoadRunner or FrankenPHP). Outside the model: a custom user whose `roles()` is not an Eloquent relation (its role names are then read inside the window), a `roles()` that queries while it is built, Swoole's preemptive coroutine scheduler, or any runtime that serves two requests at once in one process |
| Super-admin overriding a Policy | Step 3 abstains when a Policy exists; AC3 |
| 2FA skipped | API actors exempt but get no Gate pass; exempt list minimal; AC10 |
| Missing binding failing open | Boot check; AC12 |
| Domain logic leaking into the package | Arch test against `App\`; no domain hook |
| A deleted user keeping a principal, or erasure leaving a principal behind | `principals.user_id` is `ON DELETE RESTRICT`, so the database refuses any hard delete of a user with principals; `eraseUser()` erases, unlinks and deletes the user in one transaction; `UserDeletionGuard` turns an Eloquent hard delete into `UserHasPrincipalsException` (a soft delete keeps the row and passes) |
| A principal owned across organisations, or a deactivated owner still counting | Owners must be active and in the same organisation; `isActive()` walks the owner chain uncached and counts a chain longer than eight (a cycle included) as inactive; deactivation cascades to every owned principal in one transaction and `PrincipalDeactivatedEvent` fires after commit |
| Two principals for one membership, two instance principals of one type, or an organisation-less member | Membership unique index on every engine (P1); with `access.principals.organisation_required`, the instance singleton index and the organisation CHECK (triggers on SQLite, P2, P3); race-safe `forMember()` and `instance()` |
| Principals config drifting from the schema | `Testing\AssertsPrincipalsSchema` proves the refusals on the product's migrated database; P9 |
| Supply chain | Pinned Actions, `composer audit`, no lockfile, weekly grouped Dependabot updates into `develop` (Composer and Actions) |

## Development

```bash
composer update
composer test      # Pest
composer lint      # Pint
composer analyse   # PHPStan level max
```

Every class in `src/` runs mutation per class at MSI ≥ 95 via
`scripts/mutate-per-class.sh` in CI (all classes on push and nightly, the
changed ones on a pull request). Real-HTTP middleware tests use `php -S`
(no shell wrapper; torn down in `tearDown`).

## License

MIT. See [LICENSE](./LICENSE).
