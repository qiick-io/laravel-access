# Reference: schema the products own

The package owns and migrates `user_groups`, `user_group_user`,
`user_group_role` and `principals` (see `docs/public-api.md`,
Migrations). It never creates or edits Spatie's tables: Spatie's migration is
published into each product and becomes the product's own file. This page is
the normative shape of those product-owned tables (standards review A4,
design §2.1–§2.3). Products assert it with `Testing\AssertsRolesSchema`
(the `roles` columns) and `Testing\AssertsSpatieTeamSchema` (the team
columns, A3).

Timestamps are stored in UTC. Future-dated columns are `dateTime`.

## `roles`

| Column | Type | Null | Default | Index | Notes |
| --- | --- | --- | --- | --- | --- |
| `id` | bigint, auto | no | — | primary | Internal only: never in URLs, API payloads or audit properties |
| `organisation_id` | uuid | yes (null = global role) | null | team index; unique (`organisation_id`, `name`, `guard_name`) | Only when Spatie teams are on, with `permission.column_names.team_foreign_key = organisation_id` |
| `public_id` | uuid (v7) | no | generated | unique | The role's public identifier (names can be renamed) |
| `name` | string | no | — | unique (`name`, `guard_name`) when teams are off | `super-admin` is reserved for global roles (`ReservedRoleNameGuard`) |
| `guard_name` | string | no | — | as above | |
| `is_system` | boolean | no | false | — | Product flag for roles the UI must not delete |
| `is_assignable` | boolean | no | true | — | Product flag for roles the UI may assign |
| `description` | text | yes | null | — | |
| `metadata` | json | yes | null | — | Never secrets |
| `created_at`, `updated_at` | timestamp | yes | Laravel | — | |

## `permissions`, `role_has_permissions`

Spatie's shape, unchanged. A permission's public identifier is its `name`,
the value of the product's `PermissionEnum`; it has no `public_id`.

## `model_has_roles`, `model_has_permissions`

| Column | Type | Null | Index | Notes |
| --- | --- | --- | --- | --- |
| `role_id` / `permission_id` | bigint unsigned | no | primary key part; foreign key, cascade | |
| `model_type` | string | no | primary key part; index (`model_id`, `model_type`) | |
| `model_id` | bigint unsigned | no | primary key part | Only users hold roles and permissions |
| `organisation_id` | uuid | no | primary key part; team index | Only when teams are on |

No other column. Expiry and grantor columns are deliberately absent: Spatie's
`hasRole()` and `assignRole()` ignore extra pivot columns, so an expiry there
could not be enforced. Time-boxed access is a group membership with
`user_group_user.expires_at`, which `EloquentGroupRoleSource` filters in SQL.

## Audit property shape (A5)

The package writes no audit entries. The product action that changes roles,
permissions, groups or memberships writes one activity entry (Spatie events
do not cover group tables), with **public identifiers only**: no bigint id
in `properties` (conformance AC16).

| Event | Subject | Properties |
| --- | --- | --- |
| `roles_synced` (user) | user | `role_public_ids`, `role_names`, `previous_role_public_ids` |
| `permissions_synced` (role) | role | `role_public_id`, `permission_names`, `previous_permission_names` |
| `groups_synced` (user) | user | `group_public_ids`, `previous_group_public_ids`, `expires_at` per membership |
| `members_synced` (group) | group | `user_public_ids`, `previous_user_public_ids`, `expires_at` per membership |
| `roles_synced` (group) | group | `group_public_id`, `role_public_ids`, `previous_role_public_ids` |
| super-admin granted or removed | user | the matching entry above, plus `super_admin: true` |

Every entry also records the causer and, when the change came through an API
key, the key's UUID as `api_key_id`. Rows written before a product adopted
this shape keep their old shape; export and erasure read both.
