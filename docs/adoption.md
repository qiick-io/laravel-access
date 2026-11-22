# Adoption

Product adoption order (design §10): package `0.1.0` → **excubia-app** →
signaculum-core → instituta-core → **externa-core** last → then
`qiick/laravel-api-keys` in each product.

Each product:

1. Binds `ActorContext` after credential verification (`AccessContext::enter`).
2. Binds `PermissionVocabulary`, `RoleCatalog`, `GroupRoleSource` (default
   Eloquent), `TeamContext` (`NoTeamContext` today), the 2FA policy and
   responder, and the authorization responder.
3. Sets `permission.register_permission_check_method` to `false`.
4. Replaces its resolver copy, 2FA middleware, permission middleware/trait and
   `Gate::before` closure with this package.
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
membership `expires_at`). Names are not configurable (Q5). Spatie's tables
stay Spatie's. `access.migrations.load` defaults to `true`; externa 1.x sets
it to `false` and adopts the same shape through an additive migration so its
released tables keep the standard names until v2. Pre-1.0 products delete
their own `create_user_groups_tables` migration.

Products generate `user_groups.public_id` as **UUID v7** in their Eloquent
model / factory (the package migration only declares the column). The
membership FK targets `access.users_table` when set, otherwise the table from
`auth.providers.users.model`, otherwise `users`.

Assert the group schema with `Qiick\Access\Testing\AssertsGroupTablesSchema`
(AC17). When Spatie teams are on, set `permission.column_names.team_foreign_key`
to `organisation_id` and use uuid team columns; products assert that with
`Qiick\Access\Testing\AssertsSpatieTeamSchema` (A3) — run the uuid check on
PostgreSQL.

`EffectivePermissionResolver` (later PR) must apply group role ids **in the
current team** when loading permissions from those roles.

`externa-core` keeps a compatibility flag and shadow comparison before the
package `Gate::before` becomes the default (design §9).

Feature PRs fill the package surface; this page tracks the product-side steps.
