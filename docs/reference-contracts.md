# Reference: contracts

Normative public API (`@api`) from the binding design §2.2. The interfaces and
`ActorContext` / `ActorKind` ship in this package; default implementations land
in later feature PRs. This page is the name and duty list for adopters.

| Type | Duty |
| --- | --- |
| `ActorKind` | `WebSession`, `ApiKey`, `OAuthGrant`, `PublicApi`, `Unattended` |
| `ActorContext` | Kind + optional owner + credential UUID; `isApiActor()` |
| `ActorResolver` | Returns the bound context; never reads request input |
| `PermissionVocabulary` | `isPermission` / `all` over the product PermissionEnum |
| `RoleCatalog` | `superAdminRoleName()` (today `'super-admin'`) |
| `GroupRoleSource` | Role ids from active (non-soft-deleted) groups |
| `TeamContext` | `currentId` / `switchTo` (Spatie team id + cache clears) |
| `TwoFactorPolicy` | Required / usable factor / any method enabled |
| `TwoFactorResponder` | `enrol` / `unavailable` HTTP responses |
| `AuthorizationResponder` | `forbidden` HTTP response for permission middleware |

Required product bindings (boot fails otherwise): `ActorResolver`,
`RoleCatalog`, `TwoFactorPolicy`, `TwoFactorResponder`,
`AuthorizationResponder`. Package defaults exist for `ContainerActorResolver`,
`SpatiePermissionVocabulary`, `EloquentGroupRoleSource` and `NoTeamContext`.
