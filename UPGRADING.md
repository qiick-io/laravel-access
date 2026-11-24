# Upgrading

This package is **pre-release** (`0.x`). Breaking changes are allowed between
minor versions while the major is `0`, and each break is recorded here before a
tagged release.

There are no published versions yet. When `0.1.0` ships, first-install steps and
any follow-on breaks will land in this file.

## Unreleased

- `access.migrations.load` is replaced by `access.migrations.groups` and
  `access.migrations.principals` (both `true` by default). A product that set
  `load` to `false` sets `groups` to `false`; boot fails while `load` is still
  set.
- `principals.user_id` is `ON DELETE RESTRICT`: deleting a user who has a
  principal fails. After every `migrate` run the provider restores the table
  rules SQLite drops on a rebuild (`AccessSchema::ensureConstraints()`).
- Every user deletion flow (Fortify / Jetstream `DeleteUser`, admin actions,
  console commands, `model:prune`) goes through
  `PrincipalDirectory::eraseUser($user, fn ($user) => $user->forceDelete())`.
  The callback must hard-delete the row: with `SoftDeletes`, `delete()` keeps
  it, so `eraseUser()` throws and rolls back. A direct delete of a user with
  principals throws `UserHasPrincipalsException`.
