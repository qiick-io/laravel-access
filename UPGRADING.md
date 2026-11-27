# Upgrading

This package is **pre-release** (`0.x`). Breaking changes are allowed between
minor versions while the major is `0`, and each break is recorded here before a
tagged release.

## Deprecation policy

See [docs/public-api.md](docs/public-api.md#deprecation-policy).

## Unreleased

- `PrincipalDirectory::eraseUser()` is deprecated (removed in 2.0.0). Delete
  users only through the product's erasure orchestrator. It writes the
  resumable `erasure_requests` row, runs `Erasure\AccessEraser` with the
  other erasers and deletes the user row itself. `AccessEraser` dispatches
  only `SubjectErasedEvent` after commit, never `PrincipalDeactivatedEvent`,
  so a revocation listener on that event is not reached on this path.
  Revoke credentials as an orchestrator step of its own. Before the erasers
  run, take the ids of the user's principals and all their descendants from
  the snapshot, while the ownership links still exist. Then revoke every
  credential of those principals, idempotently, so a resumed request does
  it again safely. Use-time checks already refuse keys of an inactive
  principal, but the keys are not revoked until this step runs. Prove it in
  the product's C17 test.
- `GateBefore` takes no constructor argument any more: resolve it from the
  container (`app(GateBefore::class)`) or build it with `new GateBefore`.

- Rename the principal event listeners' types:
  `Qiick\Access\Principals\PrincipalCreated` →
  `Qiick\Access\Events\PrincipalCreatedEvent` and
  `Qiick\Access\Principals\PrincipalDeactivated` →
  `Qiick\Access\Events\PrincipalDeactivatedEvent` (listeners,
  `Event::listen()`, `Event::fake()` and `$listen` maps). Deploy it as a
  cutover: drain queued listeners of the old events under the old code
  first (their payloads name the old classes), then run `event:cache` again
  if the product caches events, and restart queue workers and Octane, so
  the API-key revocation listener is never skipped.
- Add `php artisan access:check` to the deploy pipeline and to the
  product's `<app>:upgrade`, after the migrations.

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
- Super-admin stands alone across every team, directly and through groups
  (Excubia #474). It is fail-closed on read: a user holding super-admin
  together with any other role gets no super-admin bypass, only the other
  roles' permissions, so a mixed account loses its super-admin powers on the
  next request. The resolver logs a warning and dispatches
  `Events\SuperAdminBypassWithheldEvent`. Listen to it and notify your
  security log or the other super-admins. Before upgrading, clean up mixed
  accounts in the product's upgrade command. Route every role and group
  write (forms, OIDC role sync, API, your commands) through
  `app(ExclusiveSuperAdmin::class)->write($user, fn () => ...)`, once per
  affected user. It locks the user row, checks the result and rolls back
  with `ExclusiveSuperAdminException`. Promote with
  `syncRoles([$superAdmin])` inside it. A custom `GroupRoleSource` that
  serves organisation groups should also implement
  `Contracts\ListsAllGroupRoles`, or only the current team and instance
  groups are checked.

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
- A published `config/access.php` that lacks a nested key now gets the
  package default for it, at every depth; a list the product sets (guards,
  principal types, exempt routes) still replaces the default whole. Boot now
  fails on a present but invalid value, naming the key: guard, type and
  route lists must be lists of strings, `principals.types` non-empty, the
  migration switches, `trust_api_guard_in_console` and
  `principals.organisation_required` real booleans (`"1"` in `.env` is a
  string), `users_table`, `group_member_model` and `gate_shadow_log_channel`
  null or a non-empty string, `permission_enum` null, empty or a backed enum
  class, and `principals.model` a `Principal` subclass.

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
