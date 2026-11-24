# Changelog

All notable changes to **laravel-access** (`qiick/laravel-access`) are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- `PrincipalCreated` is dispatched after the surrounding transaction commits
  (`ShouldDispatchAfterCommit`), as `PrincipalDeactivated` already was, so a
  rolled-back creation never reaches a listener (AC-c).

### Added

- Externa compatibility switches (design §9.2 step 6 / §9.4, removed in v2):
  `access.use_package_gate` chooses whether `GateBefore` or the product's
  `LegacyGateBefore` decides, still as the single `Gate::before` (AC1), and
  `access.gate_shadow` runs the other one too and logs disagreements without
  user data or secrets (`access.gate_shadow_log_channel`). Boot fails on a
  non-boolean switch or a missing legacy binding.

## [0.1.0] - 2026-11-24

First tagged pre-release. Public APIs may still change in any 0.x minor.

### Upgrade notes

This is the first version, so there is nothing to upgrade from; these are the
first-install requirements (details in `docs/adoption.md` and `UPGRADING.md`):

- `composer require qiick/laravel-access`, `php artisan vendor:publish
  --tag=access-config`, then `php artisan migrate` (group tables and
  `principals`; switch either set off with `access.migrations.groups` /
  `access.migrations.principals`).
- Bind `RoleCatalog`, `TwoFactorPolicy`, `TwoFactorResponder` and
  `AuthorizationResponder`; set `permission.register_permission_check_method`
  to `false` and `permission.events_enabled` to `true`. Boot fails otherwise.
- Classify every guard: boot fails unless each guard in `auth.guards` is in
  `access.web_guards` (`ACCESS_WEB_GUARDS`, default `web`) or
  `access.api_guards` (`ACCESS_API_GUARDS`). Apps with Laravel Sanctum set
  `ACCESS_API_GUARDS=sanctum`; every `auth:sanctum` request, a first-party
  SPA's cookie requests included, is then an API actor, so SPA routes use
  `auth:web`. A custom session guard (a Filament panel guard, for example)
  goes in `ACCESS_WEB_GUARDS`.
- Products with organisations set `access.principals.organisation_required`
  (and any `instance_types`) **before** migrating, and add their own restrict
  foreign key from `principals.organisation_id` to their organisations table.
- `principals.user_id` is `ON DELETE RESTRICT`: route every user deletion
  through `PrincipalDirectory::eraseUser($user, fn ($user) =>
  $user->forceDelete())`.

### Security

- On HTTP, any default guard outside `access.web_guards` is an API actor,
  listed in `access.api_guards` or not, so a guard added after boot (which the
  boot check cannot see) gets no super-admin pass from `GateBefore`.
- The super-admin role's own permissions are excluded by an exact name
  comparison for group roles too, as for direct roles: a SQL `!=` followed the
  collation and also dropped look-alikes such as `Super-Admin` on MySQL /
  MariaDB.
- A team-local Spatie role named `super-admin` (`roles.organisation_id` set)
  no longer mints an instance super-admin: the C30 read counts only global
  roles, directly and through instance groups, and the package refuses to save
  a team-local role with the reserved name (`ReservedRoleNameGuard`).
- `EloquentGroupRoleSource` gives group roles only to models on the table and
  connection of `access.group_member_model` (default
  `auth.providers.users.model`), so another guard's principal sharing an id,
  including a subclass with its own table, no longer inherits them.
- The resolver keys its cache and identity by Spatie principal (morph class +
  id), so a role event on a model sharing the morph class clears the entry,
  and `GateBefore` compares principals the same way
  (`EffectivePermissionResolver::isSamePrincipal()`).

### Changed

- The resolver reads role ids through the role model's own key (qualified in
  joins), not a hard-coded `id`. `access.web_guards` reads
  `ACCESS_WEB_GUARDS` (comma-separated, default `web`).
- The super-admin verdict is cached per principal (no team key) and cleared
  by `forget()` / `flush()`; a super-admin's `roleNamesFor()` lists the
  current team's roles plus the super-admin role, never another
  organisation's.
- `SpatieTeamContext` requires its resolver-flush closure.
- The `register_permission_check_method` boot error names the type it got.

### Added

- `principals` table (design §15): package migration
  `create_principals_table` with UUID ids, a restrict user link (erasure is a
  job), owner and creator links that survive deletion, a stored generated
  `organisation_key` behind UNIQUE (`organisation_key`, `user_id`) so one
  principal per user and organisation holds on every engine, and, when
  `access.principals.organisation_required` is true, an organisation CHECK
  (triggers on SQLite) with singleton `access.principals.instance_types` that
  have neither organisation nor user, declaratively on all four engines.
  `AccessSchema::ensureConstraints()` recreates those rules (and the
  user_groups instance rule) after a product migration rebuilds the table on
  SQLite; the provider calls it after every `migrate` run. `access.migrations.load` splits into `access.migrations.groups` and
  `access.migrations.principals`; boot fails while the old key is set.
- `PrincipalDirectory` (design §15.4) with `Principal` (UUID v7 model,
  owner/owned/user relations, `active()` and `forOrganisation()` scopes),
  `DeactivationReason`, and the `PrincipalCreated` / `PrincipalDeactivated`
  events: idempotent membership principals that refuse erased tombstones,
  service and agent accounts within the configured types and an owner chain
  of at most eight, race-safe instance singletons, transactional cascading
  deactivation, and an uncached `isActive()` that walks the owner chain.
  Owners must be active and in the same organisation; organisation ids are
  stored in lower case; only `offboarded` principals are reactivated and an
  `erased` tombstone is never rewritten; `eraseUser()` erases and unlinks a
  user's principals and deletes the user in one transaction, and
  `UserDeletionGuard` refuses any other delete of a user with principals
  (`UserHasPrincipalsException`). The model is fully guarded and
  `PrincipalDeactivated` is dispatched after commit.
- `Testing\AssertsPrincipalsSchema` (design §15.6): checks the principals
  columns and proves with rolled-back writes that the database refuses a
  second membership principal (including the NULL-organisation case), the
  deletion of a user who has a principal, the deletion of an organisation that
  has principals, an organisation-less principal when organisations are
  required, and for each declared instance type a principal with a user and a
  second principal, so config and schema cannot drift. It uses the product's
  user and organisation factories, so it runs with the organisation foreign
  key in place, and counts only integrity refusals.

- Test-only conformance helpers (design §2.1 / §13.1): `Testing\ProbeAbility`
  registers the AC2 probe ability (defined as `false`, refuses to register
  outside a unit-test run), and `Testing\DirectPermissionCheckScanner` is the
  AC9 static check: no Gate ability call with a permission in Policies, and no
  direct `hasPermission()` / `hasAssignedPermission()` outside Policies and
  the Inertia share unless `access.direct_checks` in qiick-standards.json
  lists the site with a reason, a decision and a `review_by` that has not
  passed. It reads PHP tokens, resolves names through namespaces and imports,
  ignores comments and strings, fails closed on dynamically built calls and
  `Gate::` macros in Policies, treats class attributes as part of their class,
  resolves trait aliases of watched methods, and exempts only the configured
  Inertia middleware's `share()`. `ProbeAbility` also needs
  `ProbeAbility::enable()` from the test suite.

- `EnsureUserHasPermission` route middleware (`permission:<value>`, alias
  registered by the product) and `AuthorizesWithPermission` FormRequest trait
  (design §6), sharing one decision and the product `AuthorizationResponder`
  body. API actors pass only with credential scopes (new optional
  `ApiActorScopeCheck` contract) ∩ the owner's currently assigned permissions
  (`EffectivePermissionResolver::hasAssignedPermission(..., fresh: true)`),
  never through super-admin (neither the vocabulary grant nor permissions on
  the super-admin role), and are denied when no scope check is bound.
  `permission:a,b` requires every listed permission. Web actors pass only on
  an `access.web_guards` guard and as the bound actor's user; boot fails when
  a guard in `auth.guards` is in neither `web_guards` nor `api_guards`.
  Both are per-class mutation targets.

- Group schema: nullable `user_groups.organisation_id`, `is_instance`, and an
  XOR constraint (`is_instance` true only with `organisation_id` NULL) on the
  package `create_user_groups` migration (AC17 / C75; SQLite triggers, SQL
  CHECK on MySQL / MariaDB / PostgreSQL). `GroupRoleSource::instanceRoleIdsFor`
  drives super-admin via groups; `roleIdsFor` scopes organisation groups to
  `TeamContext`. Adoption docs: SQLite does not honour `FOR UPDATE` (use MySQL,
  MariaDB or PostgreSQL in production); demotion paths outside
  `SuperAdminGuard` remain a product checklist item.
- `EnsureTwoFactorIsEnabled` middleware (design §5 / AC10): enrolment gate via
  `TwoFactorPolicy` / `TwoFactorResponder`, exempt-route wildcards (R9), API
  actors pass through. `SuperAdminGuard` + `ActiveUsers` (completeness A7):
  who may manage super-admins, protected targets, and
  `wouldLeaveNoActiveSuperAdmin()` with `FOR UPDATE` locks on active users
  and a cache/relation-free recount so concurrent demotions cannot leave
  zero super-admins. Resolver `isDirectSuperAdmin()` ignores the Spatie
  team filter for break-glass building blocks in products / laravel-oidc
  (P6 / Q3 / R7).
- `GateBefore` (single `Gate::before`: API actors never get super-admin /
  permission grants; Policy arguments abstain) and scoped
  `EffectivePermissionResolver` (direct ∪ group roles/permissions, C30
  team-filter bypass for super-admin that unsets the `roles` relation before
  and after the unfiltered read, so neither a team-filtered nor an unfiltered
  relation leaks between teams, process-local cache with request/job /
  Spatie event clears). Boot refuses any
  `permission.register_permission_check_method` other than boolean `false`
  (Spatie enables its own callback for every truthy value),
  `permission.events_enabled !== true`, and missing
  `RoleCatalog` / 2FA / `AuthorizationResponder` bindings (R4, A1, AC12).
  `GateBefore` resolves scoped deps per invoke (no cross-request resolver
  leak), abstains on bound-context / Gate user identity mismatch (type and
  identifier; the resolver cache is keyed by both too), and
  `RequestHandled` clears `AccessContext`. Under Spatie teams,
  `permissionsFor` / `roleNamesFor` agree with `isSuperAdmin` (C30).
- Actor runtime: scoped `AccessContext`, `ContainerActorResolver` (bound as
  `ActorResolver`), `CarriesActorContext` job middleware, `ActorPayloadStamper`
  for queue payload carry (sync connection nests; async replaces), Octane /
  scoped clears (no request-start middleware that would wipe outer actors on
  sub-requests), guard-signal ignored outside HTTP unless
  `access.trust_api_guard_in_console` /
  `Qiick\Access\Testing\TrustApiGuardInConsole` is enabled for product tests,
  and `Queue::createPayloadUsing` hooks left intact for other packages.
- Package-default adapters: `NoTeamContext`, `EloquentGroupRoleSource` (soft-deleted
  groups and expired memberships excluded; instance / org / legacy scoping;
  `instanceRoleIdsFor` for super-admin; fixed table names; Octane-safe
  connection resolve), `SpatiePermissionVocabulary` (from `access.permission_enum`),
  and `SpatieTeamContext` (`switchTo()` unsets the actor user's `roles` /
  `permissions` relations and keeps the bound `AccessContext` actor, flushes
  the package resolver cache through the provider-wired binding, and the
  resolver ignores `roles` / `permissions` relations loaded under another team).
  `SpatieTeamQueueBoundary` isolates Spatie team ids on async workers and
  restores the request team after sync-connection jobs.
- Package migrations for fixed `user_groups` / `user_group_user` /
  `user_group_role` (`public_id`, `metadata`, membership `expires_at`,
  nullable `organisation_id`, `is_instance` with XOR CHECK), gated by
  `access.migrations.load` (default true); optional `access.users_table`;
  `AssertsGroupTablesSchema` (AC17 / C75) and `AssertsSpatieTeamSchema` (A3).
- Contracts (`@api`): `ActorKind`, `ActorContext`, `ActorResolver`,
  `PermissionVocabulary`, `RoleCatalog`, `GroupRoleSource` (`roleIdsFor` +
  `instanceRoleIdsFor`), `TeamContext`, `TwoFactorPolicy`, `TwoFactorResponder`,
  `AuthorizationResponder`.
- Package scaffold: auto-discovered `AccessServiceProvider`, publishable
  `config/access.php` (tag `access-config`), Pest + Testbench, Pint, PHPStan
  level max, qiick standards pack pinned to app-ui `v0.12.0`
  (`f613af9febfce3b6d7f7f41785e9845a610080c7`), CI matrix (PHP 8.3/8.4/8.5 ×
  Laravel 12/13 × lowest/stable) with `composer validate --strict`,
  `composer audit`, SHA-pinned Actions, database smoke cells (SQLite / MySQL /
  MariaDB / PostgreSQL), drift-check + PR-title reusable workflows, and README
  header banners.

[Unreleased]: https://github.com/qiick-io/laravel-access/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/qiick-io/laravel-access/releases/tag/v0.1.0
