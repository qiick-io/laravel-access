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

- API keys of a super-admin owner: `EnsureUserHasPermission`,
  `AuthorizesWithPermission` and `PrincipalCeiling::allows()` now allow
  every permission of the vocabulary that the key's scopes cover (decision
  [2026-11-26](docs/decisions/2026-11-26-super-admin-key-ceiling.md)).
  Replace `hasAssignedPermission()` with `isWithinOwnerCeiling()` wherever
  the product computes a key ceiling (grantable scopes, key authorizers), so
  issuance and use agree. Rename the site in `access.direct_checks` too.
- Add `new ExclusiveSuperAdminRoleRule` to the role field of user create and
  update requests; super-admin combined with another role is then a 422.
  Accounts that already hold super-admin and other roles keep working; clean
  them up in the product's upgrade command if you want the new rule to hold
  for old rows.

- Use `EffectivePermissionResolver::isActorSuperAdmin($actor)` for super-admin
  authority in policies. Replace acting-user role checks, including in policy
  `before()` methods. `SuperAdminGuard::canManageSuperAdmins()` and
  `isProtectedFrom()` now apply the same actor-aware rule. AC9 reports
  role-only super-admin checks in policies. Use `isProtectedFrom()` for
  protected accounts and `isSuperAdmin($target)` in domain services that read
  subject role membership. See
  [adoption](docs/adoption.md) for the policy regression test setup.
- `PrincipalDirectory` adds `markErased(Principal $principal): void`. Custom
  implementations must add it. Call it for each principal of type `user` in
  the product's soft-delete transaction. The tombstone retains the user link
  and keeps the original deactivation time if the principal was already
  inactive. Use the separate erasure flow for permanent user deletion.

## 0.1.0-beta.2

- The `create_principals_table` migration adds an index on
  `principals.created_by` (pre-1.0, so the create migration changed). A
  database migrated with `0.1.0-beta.1` either runs `migrate:fresh` or adds
  the index once: `CREATE INDEX principals_created_by_index ON principals
  (created_by)` (MySQL and MariaDB already have one from the foreign key).
- Boot fails when a database connection in `database.connections` reports
  a name other than its key: a `name` option at the top level, in a read,
  write or direct variant or in its URL's query. A `name` nested anywhere
  else and a dot in a connection key fail as well. Remove that `name`, and
  move a nested connection to its own key without a dot.
- Group roles count only for exactly `access.group_member_model` (else
  `auth.providers.users.model`) on the default connection. A product with
  single-table inheritance sets `access.group_member_model` to the subclass
  it authenticates. A non-Eloquent member model (`GenericUser`) gets no
  group roles: bind your own `GroupRoleSource` for it. The multi-database
  invariants are in `docs/adoption.md`.

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
