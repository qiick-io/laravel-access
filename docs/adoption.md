# Adoption

Product adoption order (design §10): package `0.1.0` → **excubia-app** →
signaculum-core → instituta-core → **externa-core** last → then
`qiick/laravel-api-keys` in each product.

Each product:

1. Binds `ActorContext` after credential verification (`AccessContext::enter`).
2. Binds `PermissionVocabulary`, `RoleCatalog`, `GroupRoleSource` (default
   Eloquent), `TeamContext` (`NoTeamContext` today), the 2FA policy and
   responder, and the authorization responder.
3. Sets `permission.register_permission_check_method` to `false` and
   `permission.events_enabled` to `true`.
4. Replaces its resolver copy, 2FA middleware, permission middleware and
   FormRequest trait, and `Gate::before` closure with this package (see
   "Permission middleware and FormRequest trait" below).
5. Rewrites Policy `$actor->can(PermissionEnum…)` calls to the resolver (R5).
6. Lists remaining direct `hasPermission()` call sites in `qiick-standards.json`
   (`access.direct_checks`) with reason, decision link and `review_by`.

Jobs dispatched while an API actor is bound receive that actor automatically
(queue payload stamp via `ActorPayloadStamper`). Prefer
`Illuminate\Contracts\Queue\ShouldBeEncrypted` on those jobs when the queue
store is not encrypted end-to-end. `CarriesActorContext` via `through()` remains
available for explicit opt-in. The package never calls
`Queue::createPayloadUsing(null)`, so other payload hooks (including Laravel's
`illuminate:log:context`) keep working.

Organisation-scoped work must call `TeamContext::switchTo(...)` itself inside
the job (or middleware). Async workers start with a cleared Spatie team id so
job 2 never inherits job 1; a sync-connection job nested under a request
restores the request team afterwards and does not wipe it mid-request.

Under PHPUnit, `runningInConsole()` is always true, so the API-guard signal is
ignored by default. Product tests that need the guard path should call
`Qiick\Access\Testing\TrustApiGuardInConsole::enable()` (sets
`access.trust_api_guard_in_console`) and `disable()` in tearDown. The resolver
honours that flag only when `app()->environment('testing')` is true.

The package ships migrations for fixed table names `user_groups`,
`user_group_user` and `user_group_role` (with `public_id`, `metadata`,
membership `expires_at`, nullable `organisation_id`, and `is_instance`).
Names are not configurable (Q5). Spatie's tables stay Spatie's.
`access.migrations.load` defaults to `true`; externa 1.x sets it to `false`
and adopts the same shape through an additive migration so its released
tables keep the standard names until v2 (new columns stay nullable / unused
there). Pre-1.0 products delete their own `create_user_groups_tables`
migration.

Products generate `user_groups.public_id` as **UUID v7** in their Eloquent
model / factory (the package migration only declares the column). The
membership FK targets `access.users_table` when set, otherwise the table from
`auth.providers.users.model`, otherwise `users`. The instance super-admin
group uses `is_instance = true` with `organisation_id` NULL; a CHECK forbids
`is_instance = true` together with a non-null `organisation_id`.

Assert the group schema with `Qiick\Access\Testing\AssertsGroupTablesSchema`
(AC17 / C75). When Spatie teams are on, set
`permission.column_names.team_foreign_key` to `organisation_id` and use uuid
team columns; products assert that with
`Qiick\Access\Testing\AssertsSpatieTeamSchema` (A3) — run the uuid check on
PostgreSQL.

`EffectivePermissionResolver` applies group role ids from instance groups,
legacy unscoped groups, and organisation groups matching the current
`TeamContext`. Super-admin via groups counts **only** `is_instance` groups
(C30). Under teams, only the **global** super-admin role (no
`organisation_id` on the role) counts, whatever team the assignment was made
in; a team-local role with the reserved name never mints an instance
super-admin, and the package refuses to save one. Group memberships count
only for models on the table and connection of `access.group_member_model`
(default `auth.providers.users.model`), so another guard's principal with the
same id, a subclass with its own table included, inherits no group roles. With
teams, bind `TeamContext` to `SpatieTeamContext` through the container
(`singleton(TeamContext::class, SpatieTeamContext::class)`): that binding wires
the resolver flush its constructor requires. `isDirectSuperAdmin()` is the same filter-off check for a **direct**
role only — products use it with `TwoFactorPolicy::hasUsableFactor()` for
break-glass (P6); the OIDC break-glass seam stays outside this package (R7).
Register `EnsureTwoFactorIsEnabled` on the web stack; exempt routes live in
`access.two_factor.exempt_route_names`. Bind `ActiveUsers` and use
`SuperAdminGuard` for super-admin grant/revoke and last-admin protection.
Demotion (or soft-delete / expiry) paths that bypass
`SuperAdminGuard::wouldLeaveNoActiveSuperAdmin` stay a product adoption
checklist item — the package cannot see those call sites.
Products must set `permission.register_permission_check_method` to `false` and
`permission.events_enabled` to `true` before boot; the provider fails closed
otherwise (R4, A1). Bind `RoleCatalog`, `TwoFactorPolicy`,
`TwoFactorResponder` and `AuthorizationResponder` or boot fails (AC12).

## Permission middleware and FormRequest trait

The package does not register a middleware alias (it would collide with the
product's own). Register it in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->alias([
        'permission' => \Qiick\Access\Http\EnsureUserHasPermission::class,
    ]);
})
```

and gate route groups with `permission:<PermissionEnum value>`.
`permission:a,b` requires **all** listed permissions; a route with none
throws. Spatie's `a|b` and wildcard syntaxes are not supported: names match
exactly, so they deny. In a FormRequest, `use AuthorizesWithPermission;` and
call `$this->authorizePermission(PermissionEnum::X->value)` from
`authorize()`. Denials use the product `AuthorizationResponder` body in both.

A web actor passes with the permission from the resolver, super-admin
included, only when the request's guard is in `access.web_guards` (default
`web`) and its user is the bound `AccessContext` user, if one is bound. An API
actor passes only when the credential's scopes allow the permission **and** its
owner currently holds it (read fresh, never through super-admin: neither the
vocabulary grant nor permissions attached to the super-admin role count). The
scope half is the product's (or `qiick/laravel-api-keys`'s)
`ApiActorScopeCheck` binding; with none bound, every API actor is denied.

**Classify every guard.** Boot fails unless each guard in `auth.guards` is in
`access.web_guards` or `access.api_guards` (`ACCESS_API_GUARDS`, Sanctum's
`sanctum` guard included). A token guard in neither would make its users look
like web sessions. At runtime the rule holds for guards added after boot too:
on HTTP, any default guard outside `access.web_guards` resolves as an API
actor, so it never gets the super-admin pass.

`SuperAdminGuard` last-admin protection relies on `SELECT … FOR UPDATE`.
SQLite does not honour those locks; production deployments must use MySQL,
MariaDB or PostgreSQL.

`externa-core` keeps a compatibility flag and shadow comparison before the
package `Gate::before` becomes the default (design §9).

Feature PRs fill the package surface; this page tracks the product-side steps.
