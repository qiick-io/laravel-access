# Public API

This page describes `develop` once the 1.0 pull requests #59, #60, #62 and
#67 have merged; each statement that depends on one names it.

Everything listed here is the public API frozen at `1.0.0`: it changes only as
the [deprecation policy](#deprecation-policy) allows. Everything else in `src/`
is tagged `@internal` and may change in any release; products never call or
extend it. `tests/Arch/PublicApiTest.php` fails when a class is neither `@api`
nor `@internal`, or when an `@api` class is missing from this page. The duty
of each contract is in [reference-contracts.md](reference-contracts.md), the
table shapes in [reference-schema.md](reference-schema.md).

## Classes, interfaces, enums and traits

Public means the class name, its public methods, constructor, public
properties and constants (`ActorPayloadStamper::PAYLOAD_KEY`,
`AccessEraser::DELETE` / `ANONYMISE`, `ProbeAbility::NAME`, the readonly
properties of the events), and for enums the case names and backing values
(backing values are stored and compared). A method tagged `@internal` on a
public class (`EffectivePermissionResolver::cachedVerdictCount()`, the
erasure hooks of `EloquentPrincipalDirectory`) is not public.

### `Qiick\Access`

- `EffectivePermissionResolver` (class)
- `ExclusiveSuperAdmin` (class)
- `ExclusiveSuperAdminException` (class)
- `GateBefore` (class)
- `SuperAdminGuard` (class)

### `Qiick\Access\Contracts`

- `ActiveUsers` (interface)
- `ActorContext` (class)
- `ActorKind` (enum)
- `ActorResolver` (interface)
- `ApiActorScopeCheck` (interface)
- `AuthorizationResponder` (interface)
- `GroupRoleSource` (interface)
- `LegacyGateBefore` (interface)
- `ListsAllGroupRoles` (interface)
- `PermissionVocabulary` (interface)
- `RoleCatalog` (interface)
- `TeamContext` (interface)
- `TwoFactorPolicy` (interface)
- `TwoFactorResponder` (interface)

### `Qiick\Access\Erasure`

- `AccessEraser` (class)
- `ErasureReport` (class)

### `Qiick\Access\Events`

- `PrincipalCreatedEvent` (class)
- `PrincipalDeactivatedEvent` (class)
- `SubjectErasedEvent` (class)
- `SuperAdminBypassWithheldEvent` (class)

### `Qiick\Access\Http`

- `EnsureTwoFactorIsEnabled` (class)
- `EnsureUserHasPermission` (class)

### `Qiick\Access\Http\Concerns`

- `AuthorizesWithPermission` (trait)

### `Qiick\Access\Laravel`

- `AccessContext` (class)
- `AccessSchema` (class)
- `AccessServiceProvider` (class)
- `ActorPayloadStamper` (class)
- `CarriesActorContext` (class)
- `ContainerActorResolver` (class)
- `EloquentGroupRoleSource` (class)
- `NoTeamContext` (class)
- `SpatiePermissionVocabulary` (class)
- `SpatieTeamContext` (class)
- `SpatieTeamQueueBoundary` (class)

### `Qiick\Access\Principals`

- `DeactivationReason` (enum)
- `EloquentPrincipalDirectory` (class)
- `Principal` (class)
- `PrincipalCeiling` (class)
- `PrincipalDirectory` (interface)
- `UserHasPrincipalsException` (class)

### `Qiick\Access\Rules`

- `ExclusiveSuperAdminRoleRule` (class)

### `Qiick\Access\Testing`

- `AssertsGroupTablesSchema` (class)
- `AssertsPrincipalsSchema` (class)
- `AssertsRolesSchema` (class)
- `AssertsSpatieTeamSchema` (class)
- `DirectPermissionCheckScanner` (class)
- `ProbeAbility` (class)
- `TrustApiGuardInConsole` (class)

## Behaviour frozen at 1.0

These rules are part of the contract, not implementation details; each has a
test named in [security-checklist.md](security-checklist.md).

- **`Gate::before` order** (design §3.1), with the default switches:
  no user → abstain; a bound actor that is not the Gate user → abstain; an
  API actor → abstain (AC2); a Policy-backed ability → abstain (AC3);
  super-admin → grant; a vocabulary permission with **no arguments** that
  the resolver grants → grant; otherwise abstain. It never returns `false`.
  With `access.use_package_gate = false` (externa, until v2) the product's
  `LegacyGateBefore` is the one registered callback instead; with
  `access.gate_shadow` the other one also runs and only logs.
- **Super-admin stands alone** (decision
  [2026-11-26](decisions/2026-11-26-super-admin-key-ceiling.md), Excubia
  #474). `isSuperAdmin()` is false for a holder of super-admin and any other
  role, in any team, direct or through a live group. Such a holder keeps only
  the other roles, and `SuperAdminBypassWithheldEvent` is dispatched. With
  teams on, a group source without `ListsAllGroupRoles` withholds every
  bypass. `SuperAdminGuard::isProtectedFrom()` follows the assignment
  (`holdsSuperAdminRole()`). `ExclusiveSuperAdmin::write()` is the
  supported write path. It locks every affected user and rolls back a
  breaking write, or a promotion by an actor who may not manage
  super-admins.
- **Owner ceiling of an API actor** (`isWithinOwnerCeiling()`): for an
  ordinary owner, the permissions assigned to them (direct and group). For a
  super-admin owner, the whole vocabulary: never the Gate bypass, and never
  a permission outside the vocabulary. That last exclusion belongs to the
  super-admin ceiling only (decision
  [2026-11-26](decisions/2026-11-26-super-admin-key-ceiling.md)). The
  ceiling is cached for the scope unless called with `fresh: true`. The
  package's use sites (`EnsureUserHasPermission`,
  `AuthorizesWithPermission`, `PrincipalCeiling::allows()`) always read it
  fresh. The two middleware sites also intersect it with the credential's
  scopes.
- **`PrincipalCeiling::allows($principal, $permission)`** checks the ceiling
  only. It takes no scopes, so the product's key authorizer checks the key's
  scopes itself. The ceiling is the one of the user behind the nearest
  `user` principal of an active owner chain in one organisation, read in
  that organisation. An unowned service, a chain that leaves the
  organisation or reaches an instance type first, and a missing user give
  an empty ceiling.
- **Group memberships** count only for exactly the member model class on the
  default connection, skip soft-deleted groups (C7) and memberships whose
  `expires_at` has passed (AC15); super-admin through a group counts only
  `is_instance` groups (C30).
- **Principals:** `principals.user_id` is `ON DELETE RESTRICT`, so a user
  with principals is deleted only through erasure. `deactivate()` changes
  an active principal only and never rewrites an inactive one's reason or
  cutoff. `markErased()` (type `user` only) sets the reason to `Erased` on
  an active or inactive principal and keeps the cutoff of one that was
  already inactive. It leaves an `Erased` tombstone unchanged, and it keeps
  the user link (a soft-delete tombstone). No call reactivates an `Erased`
  principal, so restoring the user does not bring its principals back.
  Erasure itself anonymises the row and clears that link. `isActive()` follows at
  most eight principals, the subject included.
- **`PrincipalDirectory::eraseUser()`** is deprecated and stays until 2.0:
  products erase users through their erasure orchestrator, which calls
  `Erasure\AccessEraser`.
- **Boot fails closed** on a missing required binding (AC12), Spatie's
  permission check method on or its events off (R4, A1), an unclassified
  guard, a connection that reports another name, an Eloquent group member
  model on another connection while the package `GroupRoleSource` is bound
  (#59), and an invalid `access.*` value (#60). Group roles of a member on
  another connection are refused at lookup time as well.

## Config keys

`config/access.php`, published with the `access-config` tag. The keys and
their meaning are public; renaming or removing one is a breaking change.

| Key | Default | Meaning |
| --- | --- | --- |
| `access.api_guards` | `ACCESS_API_GUARDS`, empty | Guards whose users are API actors |
| `access.web_guards` | `ACCESS_WEB_GUARDS`, `web` | Interactive session guards; every guard is in one of the two lists |
| `access.trust_api_guard_in_console` | `false` | Tests only: honour the API-guard signal in console |
| `access.permission_enum` | `ACCESS_PERMISSION_ENUM`, null | The product's PermissionEnum for `SpatiePermissionVocabulary` |
| `access.migrations.groups` | `true` | Load the package group migration |
| `access.migrations.principals` | `true` | Load the package principals migration |
| `access.principals.model` | `Principal::class` | Principal model (a subclass is allowed) |
| `access.principals.types` | `user`, `service`, `agent` | Principal types the product uses |
| `access.principals.organisation_required` | `false` | Organisation CHECK on principals; read when the migration runs |
| `access.principals.instance_types` | empty | Singleton instance-level types; read when the migration runs |
| `access.users_table` | null (the users model's table) | Users table for the foreign keys of `user_group_user.user_id`, `principals.user_id` and `principals.created_by`, and for the erasure check |
| `access.group_member_model` | null (`auth.providers.users.model`) | The only model that gets group roles |
| `access.two_factor.exempt_route_names` | `logout`, `security.*`, `two-factor.*`, `passkey.*`, `password.confirm*` | Routes `EnsureTwoFactorIsEnabled` skips |
| `access.use_package_gate` | `ACCESS_USE_PACKAGE_GATE`, `true` | Externa only, removed in v2: `false` lets `LegacyGateBefore` decide |
| `access.gate_shadow` | `ACCESS_GATE_SHADOW`, `false` | Externa only, removed in v2: also run the other callback and log disagreements |
| `access.gate_shadow_log_channel` | `ACCESS_GATE_SHADOW_LOG_CHANNEL`, null (default channel) | Externa only, removed in v2: the shadow log channel |

Environment variables: `ACCESS_API_GUARDS`, `ACCESS_WEB_GUARDS` (both
comma-separated), `ACCESS_PERMISSION_ENUM`, and, for externa until v2,
`ACCESS_USE_PACKAGE_GATE`, `ACCESS_GATE_SHADOW` and
`ACCESS_GATE_SHADOW_LOG_CHANNEL`.

## Commands

| Command | What it does |
| --- | --- |
| `access:check` | Deploy check (F2, #62): the boot checks, the permission resolver, and the default connection (write PDO) with the Spatie tables, the group tables and `principals` when its migration is on; one line per problem, exit 1 on any. Read-only. A boot check that fails stops every Artisan command first, this one included, with the same message |

## Other names products depend on

| Name | What |
| --- | --- |
| Queue payload key `qiick_access` | `{kind, credential_uuid}` of the API actor, stamped by `ActorPayloadStamper` and read back in the worker |
| `qiick-standards.json` `access.direct_checks` | Listed direct `hasPermission()` sites for `Testing\DirectPermissionCheckScanner` (AC9): `site`, `reason`, `decision`, `review_by` |
| Probe ability `qiick-access-probe` | `ProbeAbility::NAME`, for AC2 tests |

## Middleware and validation rules

The package registers no middleware alias; products register these classes
themselves (see [adoption.md](adoption.md)).

| Class | Use |
| --- | --- |
| `Http\EnsureUserHasPermission` | `->middleware(EnsureUserHasPermission::class.':<permission>')` on routes |
| `Http\EnsureTwoFactorIsEnabled` | On the web stack; skips `access.two_factor.exempt_route_names` |
| `Http\Concerns\AuthorizesWithPermission` | FormRequest `authorize()` with the same decision |
| `Rules\ExclusiveSuperAdminRoleRule` | Role list validation: super-admin never combined with another role |

## Events and exceptions

| Type | When |
| --- | --- |
| `Events\PrincipalCreatedEvent` | After commit: a new principal row was created by `forMember`, `createService` or `instance` (not when an existing one is returned); a rolled-back creation never reaches a listener |
| `Events\PrincipalDeactivatedEvent` | After commit, once per `deactivate()` or `markErased()` call and once per principal `eraseUser()` erases. `deactivate()` preserves an inactive principal's stored reason. `markErased()` records `Erased` while retaining the user link and the original cutoff. `ownedIds` lists all directly and transitively owned principals so a listener can revoke their keys too. |
| `Principals\UserHasPrincipalsException` | An Eloquent hard delete of a user with principals outside `eraseUser()`; other deletes fail on the restrict foreign key |

Other refusals use PHP's own exceptions: `InvalidArgumentException` for a
wrong argument (an unknown principal type, an owner in another organisation),
`LogicException` for a state the design forbids (an erased principal, a user
row left after erasure), and `RuntimeException` for boot checks. Products
match on the class, never on the message.

`Events\SubjectErasedEvent` is dispatched by `Erasure\AccessEraser` after a
real (not dry-run) erasure commits.

## Tables

### Migrations

| Set | Files | Switch |
| --- | --- | --- |
| Groups | `database/migrations/groups/2026_11_22_120000_create_user_groups_tables.php` | `access.migrations.groups` |
| Principals | `database/migrations/principals/2026_11_24_100000_create_principals_table.php` | `access.migrations.principals` |

Both freeze at `1.0.0` (forward-only afterwards).

The package owns `user_groups`, `user_group_user`, `user_group_role` and
`principals`; their names are fixed. Spatie's tables stay Spatie's: the
package never creates or alters them. Products own the columns they add to
`roles` (`public_id`, …), asserted by `Testing\AssertsRolesSchema`.

## Error codes

The package defines no HTTP error codes: denials go through the product's
`AuthorizationResponder` and `TwoFactorResponder`.

## Deprecation policy

From `1.0.0` the public API is everything this page lists. Code tagged
`@internal` carries no promise.

- A public name or behaviour is removed only in a major release.
- It is first deprecated in a minor: `@deprecated` with the major that
  removes it and the replacement, a `Deprecated` entry in the CHANGELOG and
  a note in `UPGRADING.md`. It keeps working for at least one minor release before the
  major that removes it.
- A security fix may tighten behaviour in a patch or minor (a boot check
  that now fails, a check that now denies). It is listed under `Security` in
  the CHANGELOG with the steps to adopt it in `UPGRADING.md`.
- Migrations are forward-only after `1.0.0`: a schema change is a new
  migration, never an edit.
- Before `1.0.0` (`0.x`), a minor may break; each break is recorded in
  `UPGRADING.md`.
