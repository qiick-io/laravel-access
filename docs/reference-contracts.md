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
