# Upgrading

This package is **pre-release** (`0.x`). Breaking changes are allowed between
minor versions while the major is `0`, and each break is recorded here before a
tagged release.

## Unreleased

- The group migration changed before its first install: `user_groups.name`
  is no longer globally unique; `organisation_key` (stored, generated) and a
  unique (`organisation_key`, `name`) replace it. A local database migrated
  from a development build needs `migrate:fresh`.

## 0.1.0 (first tagged pre-release)

There is no earlier version. A product adopting 0.1.0 follows
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
