# Changelog

All notable changes to **laravel-access** (`qiick/laravel-access`) are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Security

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

- The super-admin verdict is cached per principal (no team key) and cleared
  by `forget()` / `flush()`; a super-admin's `roleNamesFor()` lists the
  current team's roles plus the super-admin role, never another
  organisation's.
- `SpatieTeamContext` requires its resolver-flush closure.
- The `register_permission_check_method` boot error names the type it got.

### Added

- `EnsureUserHasPermission` route middleware (`permission:<value>`, alias
  registered by the product) and `AuthorizesWithPermission` FormRequest trait
  (design §6), sharing one decision and the product `AuthorizationResponder`
  body. API actors pass only with credential scopes (new optional
  `ApiActorScopeCheck` contract) ∩ the owner's currently assigned permissions
  (`EffectivePermissionResolver::hasAssignedPermission(..., fresh: true)`),
  never through super-admin, and are denied when no scope check is bound.
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
