# Upgrading

This package is **pre-release** (`0.x`). Breaking changes are allowed between
minor versions while the major is `0`, and each break is recorded here before a
tagged release.

## Deprecation policy

From `1.0.0` the public API is everything tagged `@api` in `src/`, the
`access.*` config keys and their `ACCESS_*` environment variables, the
events, and the migrations (see `docs/reference-contracts.md`). Code tagged
`@internal` carries no promise.

- A public name or behaviour is removed only in a major release.
- It is first deprecated in a minor: `@deprecated` with the major that
  removes it and the replacement, a `Deprecated` entry in the CHANGELOG and
  a note here. It keeps working for at least one minor release before the
  major that removes it.
- A security fix may tighten behaviour in a patch or minor (a boot check
  that now fails, a check that now denies). It is listed under `Security` in
  the CHANGELOG with the steps to adopt it here.
- Migrations are forward-only after `1.0.0`: a schema change is a new
  migration, never an edit.
- Before `1.0.0` (`0.x`), a minor may break; each break is recorded here.

## Unreleased

- The `create_principals_table` migration adds an index on
  `principals.created_by` (pre-1.0, so the create migration changed). A
  database migrated with `0.1.0-beta.1` either runs `migrate:fresh` or adds
  the index once: `CREATE INDEX principals_created_by_index ON principals
  (created_by)` (MySQL and MariaDB already have one from the foreign key).
- Each effective database connection name must stand for one database for
  the whole process (`docs/adoption.md`). Opening a connection
  throws when its configured `name`, a URL's `?name=` option or its
  writer's `name` matches another connection of a different database, when
  its writer variants are different databases, or when the alias was
  repointed at another database after it was opened. A product that
  switches tenants by repointing one alias gives each tenant database its
  own connection name instead.

## 0.1.0-beta.1 (first tagged pre-release)

There is no earlier version. A product adopting 0.1.0-beta.1 follows
`docs/adoption.md`; the points that change existing product code:

- Migrations are switched per set with `access.migrations.groups` and
  `access.migrations.principals` (both `true` by default). A product that
  keeps its own group tables (externa 1.x) sets `groups` to `false`. The
  pre-release key `access.migrations.load` makes boot fail.
- Every guard in `auth.guards` is classified in `access.web_guards` or
  `access.api_guards`, or boot fails (Sanctum: `ACCESS_API_GUARDS=sanctum`).
- Product permission middleware and FormRequest helpers are replaced by
  `EnsureUserHasPermission` (alias it as `permission`) and
  `AuthorizesWithPermission`; Policies check permissions through the resolver,
  never `$actor->can(<permission>)`.
- `principals.user_id` is `ON DELETE RESTRICT`: deleting a user who has a
  principal fails. After every `migrate` run the provider restores the table
  rules SQLite drops on a rebuild (`AccessSchema::ensureConstraints()`).
- Every user deletion flow (Fortify / Jetstream `DeleteUser`, admin actions,
  console commands, `model:prune`) goes through
  `PrincipalDirectory::eraseUser($user, fn ($user) => $user->forceDelete())`.
  The callback must hard-delete the row: with `SoftDeletes`, `delete()` keeps
  it, so `eraseUser()` throws and rolls back. A direct delete of a user with
  principals throws `UserHasPrincipalsException`.
- New config keys `access.use_package_gate` (default `true`),
  `access.gate_shadow` (default `false`) and `access.gate_shadow_log_channel`
  for externa's switch to the package `Gate::before` (design §9). Nothing
  changes with the defaults; a published config does not need them. They are
  removed in v2.
- The group migration changed before its first install: `user_groups.name`
  is no longer globally unique; `organisation_key` (stored, generated) and a
  unique (`organisation_key`, `name`) replace it. A local database migrated
  from a development build needs `migrate:fresh`.
