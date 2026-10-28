# Step 5 — RBAC administration (plan Phase 3)

## Existing functionality and completed work

The foundation already had users, school-specific roles, a global permission catalog, pivot tables,
permission gates, school context and transactional audit logging. This phase reuses them.

Added Administration → Users, Roles and Permissions screens:

- Paginated school account listing with eager-loaded roles.
- Account creation and editing, multiple role assignments, password changes and enable/disable controls.
- Paginated school role listing, role creation/renaming, grouped permission checkboxes.
- Read-only permission catalog; permission names are defined by the application.
- Read-only administration for accounts with rbac.view but without rbac.manage.
- Self-disabling and last-active-RBAC-manager protection.

No new packages or duplicate RBAC implementation were introduced.

## Files created

- app/Services/RbacGuard.php
- app/Services/UserAdministrationService.php
- app/Policies/UserPolicy.php
- app/Policies/RolePolicy.php
- app/Http/Requests/SaveManagedUserRequest.php
- app/Http/Requests/SaveRoleRequest.php
- app/Http/Controllers/ManagedUserController.php
- app/Http/Controllers/RoleAdministrationController.php
- resources/views/components/admin-feedback.blade.php
- resources/views/administration/users/index.blade.php
- resources/views/administration/users/form.blade.php
- resources/views/administration/roles/index.blade.php
- resources/views/administration/roles/form.blade.php
- resources/views/administration/permissions.blade.php
- tests/Feature/RbacAdministrationTest.php
- development/step-5.md

## Files modified

- app/Models/User.php: role relationship for eager-loaded account listing
- app/Services/AccessManager.php: role creation/renaming, school lock, fresh actor authorization and manager-retention checks
- app/Http/Requests/Auth/LoginRequest.php: inactive accounts cannot authenticate with credentials
- app/Http/Middleware/ResolveSchool.php: refresh account state before school access checks
- routes/web.php: scoped managed-user binding and administration routes
- resources/views/components/app-layout.blade.php: Administration navigation
- resources/views/dashboard.blade.php: status feedback after relinquishing administration access
- README.md: current progress, verification and next phase

## Database changes

No migrations or new tables/columns. Account and role changes use the existing users, roles, user_roles,
role_permissions, permissions, audit_logs and sessions tables. Global email uniqueness and school-specific
role names are retained. No live accounts were automatically created, edited or granted permissions.

## Routes

All routes below require authentication, active account/school context and rbac.view:

- GET /administration/users: paginated school accounts
- GET /administration/users/create and POST /administration/users: account creation
- GET /administration/users/{managed_user}/edit: account details, editable only with rbac.manage
- PUT /administration/users/{managed_user}: account changes
- GET /administration/roles: paginated school roles
- GET /administration/roles/create and POST /administration/roles: role creation
- GET /administration/roles/{role}/edit: role details and permissions
- PUT /administration/roles/{role}: rename/update permissions
- GET /administration/permissions: read-only catalog

There are no account/role deletion routes or permission-name editing routes.

## Authorization and transactional safeguards

Users remain globally identifiable for login. The managed_user route binding explicitly filters by current
school; it does not globally scope authentication's User model. Role binding uses the existing school scope.
Foreign-school account/role URLs return 404; submitted foreign-school role IDs fail validation.
Policies and Form Requests require rbac.manage for changes, in addition to rbac.view.
The mutation services independently authorize and check ownership.

Every UI RBAC mutation locks the parent school row, then rechecks the actor's persisted permissions.
All changes and their audit records share a transaction. Role/permission changes and user status changes
must leave at least one active account in that school with BOTH rbac.view and rbac.manage, possibly
received from multiple roles. Inactive accounts and managers in other schools do not count.
This guard uses effective permissions, not role names. School locking serializes competing RBAC changes.

Self-disabling is prohibited even when another manager exists. An account may relinquish its own roles or
permissions if another manager remains; it then redirects to the dashboard if it loses administration access.
Trusted provisioning/operator commands remain deliberate recovery tools and do not apply UI lockout safeguards.

## Account handling and auditing

Passwords require at least 12 characters with confirmation; they are hashed through the existing User cast.
Blank password input on an existing account preserves its hash. Password fields are never repopulated or
audit logged. Account audit snapshots include identity/status and a credentials_changed marker only.
Email changes clear email verification. Credential changes and disabling rotate remember_token and remove
stored database sessions for that account. Disabled users cannot sign in with credentials and existing
school requests re-read the account's active flag.

USER_CREATED, USER_UPDATED, USER_ROLES_CHANGED, ROLE_CREATED, ROLE_UPDATED and ROLE_PERMISSIONS_CHANGED
capture before/after values with actor, school and timestamp. Audit failures roll back associated changes,
including newly created accounts and role assignments. The existing read-only Audit logs screen displays them.

## Manual testing

1. Sign in as a provisioned school administrator and open Administration → Users, Roles and Permissions.
2. Open Roles → Add role. Create OFFICE_READER and select school.view and rbac.view. Save.
3. Open Users → Add user. Enter a name, unique email, matching 12+ character password, Active status,
   and OFFICE_READER. Save. Verify role assignment in the user listing.
4. Sign in with that account in a private browser: it can inspect administration but has no Save/Create
   controls and cannot mutate users/roles. School Details is also read-only.
5. As the administrator, rename the account and save without password input; verify the old password still works.
6. Disable that account. Verify it cannot sign in or access school pages. Enable it and test login again.
7. Change its password with confirmation. Verify the new password works and the old password fails.
8. Try to disable your own account, remove your own last-manager role, or remove all RBAC permissions
   from the sole manager role. Verify validation errors and retained access.
9. Add a second active manager before deliberately removing your own administrative access. Confirm
   the change succeeds and redirects to the dashboard when you lose rbac.view.
10. Inspect Audit logs for account changes, role assignments and role-permission edits.

## Verification

66 tests passed with 341 assertions on school_erp_testing. New tests cover all administration pages,
account creation/editing, password hashing/preservation/reset, disabled login/access, school isolation,
foreign role IDs, view-only behavior, role/permission changes, input validation, last-manager and self-disable
protection, deliberate self-demotion, session cleanup, secret exclusion and audit rollback.
Prior authentication, academic-year, school settings and RBAC foundation tests remain passing.
Production asset build, Pint, Blade compilation and changed-code whitespace checks passed.
Browser appearance has not been visually verified.

## Assumptions and pending work

Users are disabled rather than physically deleted; roles are retained rather than deleted.
Permission definitions remain application-controlled; catalog entries for later modules do not create those modules.
Teachers' class/section/subject assignment restrictions will be enforced when those domain modules are built.
Existing teacher/principal/etc. roles are not automatically granted broad new permissions.
The existing trusted provisioning command can restore SCHOOL_ADMIN grants if intentionally rerun.
No email invitations, password-reset email flow or employee account linkage is included in this phase.
The local MySQL version remains unchanged.

## Next recommended phase

Employee Management (plan Phase 4): school-scoped employee records, authorized CRUD/deactivation,
validation, photo uploads, audit logging and tests. Teacher profiles follow as a separate module.
