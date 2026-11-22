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

Under PHPUnit, `runningInConsole()` is always true, so the API-guard signal is
ignored by default. Product tests that need the guard path should call
`Qiick\Access\Testing\TrustApiGuardInConsole::enable()` (sets
`access.trust_api_guard_in_console`) and `disable()` in tearDown. The resolver
honours that flag only when `app()->environment('testing')` is true.

`externa-core` keeps a compatibility flag and shadow comparison before the
package `Gate::before` becomes the default (design §9). No schema change is
required for package adoption itself.

Feature PRs fill the package surface; this page tracks the product-side steps.
