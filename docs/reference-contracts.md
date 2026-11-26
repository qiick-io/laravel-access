# Reference: contracts

Normative public API (`@api`) from the binding design §2.2. Package defaults:
`NoTeamContext`, `EloquentGroupRoleSource`, `SpatiePermissionVocabulary` (when
`access.permission_enum` is set). `SpatieTeamContext` is available for products
that enable Spatie teams. This page is the name and duty list for adopters.

| Type | Duty |
| --- | --- |
| `ActorKind` | `WebSession`, `ApiKey`, `OAuthGrant`, `PublicApi`, `Unattended` |
| `ActorContext` | Kind + optional owner + credential UUID; `isApiActor()` |
| `ActorResolver` | Returns the bound context; never reads request input |
| `PermissionVocabulary` | `isPermission` / `all` over the product PermissionEnum |
| `RoleCatalog` | `superAdminRoleName()` (today `'super-admin'`) |
| `GroupRoleSource` | `roleIdsFor` (instance + legacy unscoped + current-org groups) and `instanceRoleIdsFor` (is_instance only, for super-admin). Soft-deleted / expired memberships excluded. Permission rows for returned role ids load **in the current Spatie team** |
| `TeamContext` | `currentId` / `switchTo` (Spatie team id + cache clears). Products that enable teams set `permission.column_names.team_foreign_key` to `organisation_id` (A3) |
| `TwoFactorPolicy` | Required / usable factor / any method enabled |
| `TwoFactorResponder` | `enrol` / `unavailable` HTTP responses |
| `AuthorizationResponder` | `forbidden` HTTP response for permission middleware |
| `ApiActorScopeCheck` | Optional. `allows(ActorContext, permission)`: whether an API actor's credential scopes cover the permission, for `EnsureUserHasPermission` / `AuthorizesWithPermission`. Unbound → every API actor is denied |
| `ActiveUsers` | `query(): Builder` of users that count as active for `SuperAdminGuard` |
| `LegacyGateBefore` | Deprecated, removed in v2 (externa only, design §9). `decide(?Authenticatable, ability, arguments): ?bool`, the product's pre-package `Gate::before`. Required when `access.use_package_gate` is false or `access.gate_shadow` is true |

Required product bindings (boot fails otherwise): `ActorResolver`,
`RoleCatalog`, `TwoFactorPolicy`, `TwoFactorResponder`,
`AuthorizationResponder`. Bind `ActiveUsers` before resolving `SuperAdminGuard`.
Package defaults exist for `ContainerActorResolver`,
`SpatiePermissionVocabulary`, `EloquentGroupRoleSource` and `NoTeamContext`.

## Public API

Everything tagged `@api` in `src/` is public and follows semantic versioning
from `1.0.0`. Everything tagged `@internal` (`AccessBootChecks`,
`GateBeforeSelector`, `MigrationSettings`, `ReservedRoleNameGuard`,
`UserDeletionGuard`) can change
in any release: products never call or extend it. Besides the contracts
above, the public classes are:

| Area | Classes |
| --- | --- |
| Gate and resolver | `GateBefore`, `EffectivePermissionResolver`, `SuperAdminGuard` |
| HTTP | `Http\EnsureUserHasPermission`, `Http\EnsureTwoFactorIsEnabled`, `Http\Concerns\AuthorizesWithPermission` |
| Validation | `Rules\ExclusiveSuperAdminRoleRule` |
| Actor runtime | `Laravel\AccessContext`, `Laravel\ContainerActorResolver`, `Laravel\CarriesActorContext`, `Laravel\ActorPayloadStamper`, `Laravel\SpatieTeamQueueBoundary` |
| Adapters | `Laravel\NoTeamContext`, `Laravel\SpatieTeamContext`, `Laravel\EloquentGroupRoleSource`, `Laravel\SpatiePermissionVocabulary` |
| Schema | `Laravel\AccessSchema`, `Laravel\AccessServiceProvider` |
| Principals | `Principals\PrincipalDirectory`, `Principals\EloquentPrincipalDirectory`, `Principals\Principal`, `Principals\DeactivationReason`, `Principals\PrincipalCeiling` |
| Test helpers | `Testing\ProbeAbility`, `Testing\DirectPermissionCheckScanner`, `Testing\TrustApiGuardInConsole`, `Testing\AssertsGroupTablesSchema`, `Testing\AssertsSpatieTeamSchema`, `Testing\AssertsPrincipalsSchema`, `Testing\AssertsRolesSchema` |

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
| `access.two_factor.exempt_route_names` | logout, security, two-factor, passkey, password confirmation | Routes `EnsureTwoFactorIsEnabled` skips |
| `access.use_package_gate` | `ACCESS_USE_PACKAGE_GATE`, `true` | Externa only, removed in v2: `false` lets `LegacyGateBefore` decide |
| `access.gate_shadow` | `ACCESS_GATE_SHADOW`, `false` | Externa only, removed in v2: also run the other callback and log disagreements |
| `access.gate_shadow_log_channel` | `ACCESS_GATE_SHADOW_LOG_CHANNEL`, null (default channel) | Externa only, removed in v2: the shadow log channel |

Environment variables: `ACCESS_API_GUARDS`, `ACCESS_WEB_GUARDS` (both
comma-separated), `ACCESS_PERMISSION_ENUM`, and, for externa until v2,
`ACCESS_USE_PACKAGE_GATE`, `ACCESS_GATE_SHADOW` and
`ACCESS_GATE_SHADOW_LOG_CHANNEL`.

## Events and exceptions

| Type | When |
| --- | --- |
| `Principals\PrincipalCreated` | After commit: a new principal row was created by `forMember`, `createService` or `instance` (not when an existing one is returned); a rolled-back creation never reaches a listener |
| `Principals\PrincipalDeactivated` | After commit, once per `deactivate()` or `markErased()` call and once per principal `eraseUser()` erases. `deactivate()` preserves an inactive principal's stored reason. `markErased()` records `Erased` while retaining the user link and the original cutoff. `ownedIds` lists all directly and transitively owned principals so a listener can revoke their keys too. |
| `Principals\UserHasPrincipalsException` | An Eloquent hard delete of a user with principals outside `eraseUser()`; other deletes fail on the restrict foreign key |

Other refusals use PHP's own exceptions: `InvalidArgumentException` for a
wrong argument (an unknown principal type, an owner in another organisation),
`LogicException` for a state the design forbids (an erased principal, a user
row left after erasure), and `RuntimeException` for boot checks. Products
match on the class, never on the message.

## Migrations

| Set | Files | Switch |
| --- | --- | --- |
| Groups | `database/migrations/groups/2026_11_22_120000_create_user_groups_tables.php` | `access.migrations.groups` |
| Principals | `database/migrations/principals/2026_11_24_100000_create_principals_table.php` | `access.migrations.principals` |

Both freeze at `1.0.0` (forward-only afterwards).
