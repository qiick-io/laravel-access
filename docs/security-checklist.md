# Security checklist: properties and the tests that cover them

The binding design numbers the security properties the package must keep:
`AC1`–`AC12` (design §7), `AC13`–`AC17` (standards review §3.3), the fleet
checks `C7`, `C29` and `C30`, and `P1`–`P9` for principals (design §15.7).
Each row names the test that fails when the property breaks, so 1.0
checklist item 2 can be checked line by line. Test names are quoted from the
`it()` description, after the file in `tests/`. **Product** marks a property
only a product can prove (its own code or data); the fleet conformance
catalogue in `tests/Conformance` runs those checks in the product, and the
package ships the helper named in the row. **Partial** marks a property whose
package test covers only part of it.

| # | Property | Covering test | Status |
| --- | --- | --- | --- |
| AC1 | Exactly one `Gate::before`, and it is `GateBefore`; Spatie's permission check method off | `Feature/GateBeforeBootTest` › registers exactly one Gate::before callback that is GateBefore (AC1); `Feature/GateShadowModeTest` › keeps exactly one Gate::before callback (AC1); `Feature/BootChecksPhpUnitTest` › fails when register_permission_check_method is anything but boolean false (R4) | Covered |
| AC2 | Super-admin web session passes a `false` probe; the same user behind a key, a grant or the public door does not | `Unit/GateBeforeTest` › abstains unconditionally for API actors even when they are super-admin (AC2); `Feature/Testing/ProbeAbilityTest` › passes for a super-admin web session and fails behind every API actor kind (AC2) | Covered |
| AC3 | A Policy-backed ability is never pre-empted by super-admin | `Unit/GateBeforeTest` › abstains for Policy-backed abilities so super-admin cannot pre-empt (AC3) | Covered |
| AC4 | The actor kind cannot be forged by request input | `Unit/ContainerActorResolverTest` › keeps the API guard signal when forged input claims web (AC4) | Covered |
| AC5 | A job dispatched by a key request is still an API actor in the worker | `Feature/ActorJobPropagationTest` › re-binds the stamped API actor when a database queue worker runs the job (AC5) | Covered |
| AC6 | An owner who loses a permission: the next key request is refused | `Feature/EnsureUserHasPermissionTest` › denies an API actor as soon as its owner loses the permission (AC6) | Covered |
| AC7 | Group-only permissions are visible through `can()` in a web session | `Feature/EnsureUserHasPermissionTest` › shows a group-only permission through can() in a web session (AC7) | Covered |
| AC8 | A group-only permission still works on an API path, within the scopes | `Feature/EnsureUserHasPermissionTest` › lets a group-only permission of the owner through on an API path, within the scopes (AC8) | Partial: the product's Policies must use the resolver; the scanner (AC9) enforces it |
| AC9 | No `->can(<PermissionEnum>)` in Policies, no unlisted direct `hasPermission()` | `Unit/Testing/DirectPermissionCheckScannerTest` › flags ->can() with the permission enum inside a Policy, however the enum is named (AC9); › flags an unlisted direct hasPermission() outside Policies with its site | Covered (the product runs the scanner) |
| AC10 | 2FA: required and unsatisfied → enrol; exempt route → through; API actor → through | `Unit/EnsureTwoFactorIsEnabledTest` › returns enrol when 2FA is required and unsatisfied (AC10); › passes through exempt route names including wildcards (R9 / AC10); › passes through API actors without enrolment (AC10) | Covered |
| AC11 | No cache leak across requests or jobs | `Feature/GateBeforeScopedResolverTest` › does not leak resolver cache across consecutive request scopes after a demotion (AC11); `Feature/SuperAdminGuardScopeTest` (after #67) | Covered |
| AC12 | Boot fails when a required binding is missing | `Feature/BootChecksPhpUnitTest` › fails when a required product binding is missing (AC12) | Covered |
| AC13 | `events_enabled` is true, and a role detach clears the resolver in the same request | `Feature/BootChecksPhpUnitTest` › fails when permission.events_enabled is not true (A1 / N3 / AC13); `Feature/EnsureUserHasPermissionTest` › clears the cached permission of a user whose role is detached in the same request (AC13) | Covered |
| AC14 | With teams on: `team_foreign_key = organisation_id` and uuid team columns | `Unit/AssertsSpatieTeamSchemaTest` › fails when teams are on and team_foreign_key is not organisation_id (A3 / AC14); › fails on PostgreSQL when organisation_id is not uuid (A3 / AC14) | Covered (the product runs `AssertsSpatieTeamSchema`) |
| AC15 | An expired membership grants nothing, super-admin through a group included | `Feature/EloquentGroupRoleSourceTest` › ignores memberships whose expires_at is in the past (A2 / AC15) | Covered |
| AC16 | Role, group and membership activity entries carry no bigint id | — | Product: the package writes no activity entries |
| AC17 | The group migrations create the three tables on four engines and change no Spatie table | `Feature/GroupTablesMigrationTest` › creates the package group tables with expires_at public_id and metadata, leaving Spatie tables alone (AC17) (CI: SQLite, PostgreSQL, MySQL, MariaDB); `Unit/AssertsGroupTablesSchemaTest` › passes AC17 when the package migration shape is present; › fails AC17 when a required pivot index is missing | Covered |
| C7 | A soft-deleted group grants nothing | `Feature/EloquentGroupRoleSourceTest` › excludes soft-deleted groups so they grant nothing (C7) | Covered |
| C29 | No organisation bleed: the team id keys the cache, `switchTo()` clears it | `Feature/EffectivePermissionResolverTeamFilterTest` › drops the resolver cache on a team switch so a revoked permission is not served when the team returns (C29) | Covered |
| C30 | Instance super-admin only through the global role or an `is_instance` group | `Feature/EffectivePermissionResolverTeamFilterTest` › ignores the team filter for a global super-admin assigned in no current team (C30) | Covered |
| P1 | One principal per membership, no NULL hole | `Feature/PrincipalsMigrationTest` › allows one principal per user and organisation, and one per user at instance level (P1); `Feature/AssertsPrincipalsSchemaTest` › fails on the NULL hole of a unique index on (organisation_id, user_id) (P1) | Covered |
| P2 | No instance-level principal by mistake when organisations are required | `Feature/PrincipalsMigrationTest` › enforces the organisation rule and the instance singleton when organisations are required (P2, P3); `Feature/Principals/PrincipalDirectoryTest` › refuses an organisation-less membership when organisations are required (P2) | Covered |
| P3 | One instance principal per type, also under a race | `Feature/Principals/PrincipalDirectoryTest` › gives concurrent processes one instance principal and one membership principal (P1, P3); › keeps instance principals singletons and only for declared types (P3) | Covered |
| P4 | A deactivated, expired or orphaned principal is inactive at use time | `Feature/Principals/PrincipalDirectoryTest` › reports a principal active only when it, and every owner up the chain, is (P4, P7) | Partial: rejecting the key at use time is `qiick/laravel-api-keys` |
| P5 | Deleting a principal never erases its keys or audit | — | Out of scope: the `api_keys` restrict FK is `qiick/laravel-api-keys` |
| P6 | Deleting an organisation never cascades principals | `Feature/AssertsPrincipalsSchemaTest` › fails when an organisation with principals can be deleted (§15.6, P6) | Covered (the product owns the FK and runs the helper) |
| P7 | A user deleted from `users` leaves no live `user` principal | `Feature/Principals/PrincipalDirectoryTest` › reports a principal active only when it, and every owner up the chain, is (P4, P7); `Feature/PrincipalsMigrationTest` › refuses to delete a user who still has a principal, and keeps principals when their owner or creator goes; `Feature/Principals/PrincipalDirectoryTest` › erases a user: principals erased and unlinked, then the user deleted, in one transaction (decision 2026-11-24) | Covered |
| P8 | No ownership cycle; the owner chain stops at eight | `Feature/Principals/PrincipalDirectoryTest` › refuses an owner whose chain is cyclic or deeper than eight (P8); › accepts an owner chain of seven and refuses one of eight with the exact message (P8) | Covered |
| P9 | Config and schema never drift | `Feature/AssertsPrincipalsSchemaTest` › fails when the config requires organisations but the schema does not enforce them (P2, P9); › fails when a declared instance type is not a singleton (P3, P9) | Covered |

Summary: 24 covered, 2 partial (AC8, P4), 2 product or out of scope (AC16, P5), 0 gap.
