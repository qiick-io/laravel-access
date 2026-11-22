# Changelog

All notable changes to **laravel-access** (`qiick/laravel-access`) are
documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
  team-filter bypass for super-admin, process-local cache with request/job /
  Spatie event clears). Boot refuses
  `permission.register_permission_check_method === true`,
  `permission.events_enabled !== true`, and missing
  `RoleCatalog` / 2FA / `AuthorizationResponder` bindings (R4, A1, AC12).
  `GateBefore` resolves scoped deps per invoke (no cross-request resolver
  leak), abstains on bound-context / Gate user identity mismatch, and
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
  groups and expired memberships excluded; fixed table names; Octane-safe
  connection resolve), `SpatiePermissionVocabulary` (from `access.permission_enum`),
  and `SpatieTeamContext` (clears the AccessContext actor).
  `SpatieTeamQueueBoundary` isolates Spatie team ids on async workers and
  restores the request team after sync-connection jobs.
- Package migrations for fixed `user_groups` / `user_group_user` /
  `user_group_role` (`public_id`, `metadata`, membership `expires_at`), gated by
  `access.migrations.load` (default true); optional `access.users_table`;
  `AssertsGroupTablesSchema` (AC17) and `AssertsSpatieTeamSchema` (A3).
- Contracts (`@api`): `ActorKind`, `ActorContext`, `ActorResolver`,
  `PermissionVocabulary`, `RoleCatalog`, `GroupRoleSource`, `TeamContext`,
  `TwoFactorPolicy`, `TwoFactorResponder`, `AuthorizationResponder`.
- Package scaffold: auto-discovered `AccessServiceProvider`, publishable
  `config/access.php` (tag `access-config`), Pest + Testbench, Pint, PHPStan
  level max, qiick standards pack pinned to app-ui `v0.12.0`
  (`f613af9febfce3b6d7f7f41785e9845a610080c7`), CI matrix (PHP 8.3/8.4/8.5 ×
  Laravel 12/13 × lowest/stable) with `composer validate --strict`,
  `composer audit`, SHA-pinned Actions, database smoke cells (SQLite / MySQL /
  MariaDB / PostgreSQL), drift-check + PR-title reusable workflows, and README
  header banners.
