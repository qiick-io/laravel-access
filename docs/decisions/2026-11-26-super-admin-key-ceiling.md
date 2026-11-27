# Super-admin key ceiling and exclusive super-admin role

- Date: 2026-11-26
- Status: accepted (the maintainer)
- Found in: the Excubia stress test, with `0.1.0-beta.2`

## Context

An account with only the super-admin role could not create an API key:
the owner ceiling counted assigned permissions only, and super-admin works
through the Gate bypass, not through assigned permissions. Its ceiling was
empty, so every scope was out of reach and the maintainer had to add the
admin role to their own account. The user form also allowed super-admin
next to other roles, which grants nothing and hides who is a super-admin.

## Decision

1. **Super-admin is exclusive.** A user's role list holds super-admin alone
   or any combination of other roles. `Rules\ExclusiveSuperAdminRoleRule`
   is the server check; the app-ui role picker disables the other roles
   once super-admin is picked.
   The rule covers direct and group roles in every team (added
   2026-11-27 after Excubia #474, approved by the maintainer). It is
   enforced on read, fail-closed: a holder of super-admin and another role
   gets no super-admin bypass, only the other roles, and
   `SuperAdminBypassWithheldEvent` is dispatched. On write,
   `ExclusiveSuperAdmin::write()` is the one supported path: a transaction
   with the user row locked, a check of the resulting state, a rollback on
   a breach. A write that skips it cannot hide a super-admin.
2. **Permissions add up across roles**, as Spatie already resolves them;
   the picker says so in a short helper text.
3. **The key ceiling of a super-admin is the vocabulary.**
   `EffectivePermissionResolver::isWithinOwnerCeiling()` is true when the
   owner is assigned the permission, or is an instance super-admin and the
   permission is in the product's `PermissionVocabulary`. The middleware,
   the FormRequest trait and `PrincipalCeiling::allows()` use it.

## What does not change

- API actors never get the super-admin Gate bypass (design §3.1 step 2,
  `isActorSuperAdmin()` stays false for them).
- A key still needs its own scopes: the ceiling only bounds them.
- Nothing beyond the vocabulary: a permission only the super-admin role row
  carries stays out, as does a team-local role named super-admin.
- The ceiling is read fresh at use time, so a demoted or deleted owner's
  keys lose the grant on the next request; inactive owner chains still give
  an empty ceiling.
