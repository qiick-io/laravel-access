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
| `GroupRoleSource` | Role ids from active groups (not soft-deleted, membership not expired). Ids are instance-level; `EffectivePermissionResolver` must load their permissions **in the current Spatie team** |
| `TeamContext` | `currentId` / `switchTo` (Spatie team id + cache clears). Products that enable teams set `permission.column_names.team_foreign_key` to `organisation_id` (A3) |
| `TwoFactorPolicy` | Required / usable factor / any method enabled |
| `TwoFactorResponder` | `enrol` / `unavailable` HTTP responses |
| `AuthorizationResponder` | `forbidden` HTTP response for permission middleware |

Required product bindings (boot fails otherwise): `ActorResolver`,
`RoleCatalog`, `TwoFactorPolicy`, `TwoFactorResponder`,
`AuthorizationResponder`. Package defaults exist for `ContainerActorResolver`,
`SpatiePermissionVocabulary`, `EloquentGroupRoleSource` and `NoTeamContext`.
