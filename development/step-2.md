# Step 2 — school context, RBAC and audit foundation

## Existing functionality

Laravel 13.38, login/logout, a shared Blade/Tailwind layout, and an authenticated dashboard existed.
There were no school, role, permission, or audit tables. No new packages were required.

## Database changes

One additive migration creates schools, permissions, roles, role_permissions, user_roles and audit_logs.
Users gain nullable school_id (for explicit onboarding), is_active, and a composite school/id unique key.
Schools have code, name, timezone and is_active; the next phase extends this same table with school details.
Unique school/role names and composite foreign keys prevent cross-school pivot relationships.
Audit queries have school/time and school/entity indexes. School/account deletions referenced by history are restricted.
Existing users are not automatically assigned a school or permissions.

## Files created

- database/migrations/2026_10_27_100000_create_school_access_foundation.php
- app/Models/School.php, Role.php, AuditLog.php
- app/Models/Concerns/BelongsToSchool.php
- app/Support/CurrentSchool.php
- app/Http/Middleware/ResolveSchool.php
- app/Policies/SchoolPolicy.php
- app/Services/AccessManager.php, AuditLogger.php, ProvisionSchool.php
- app/Http/Controllers/AuditLogController.php
- config/permissions.php
- database/factories/SchoolFactory.php
- resources/views/administration/audit-logs.blade.php
- tests/Feature/SchoolAccessTest.php

## Files modified

- app/Models/User.php: school relation, active flag, permission lookup
- app/Providers/AppServiceProvider.php: scoped context binding and permission gates
- bootstrap/app.php: school middleware alias
- routes/web.php: school-protected dashboard and permission-protected audit route
- routes/console.php: explicit school onboarding and user creation
- database/factories/UserFactory.php: school-backed test users
- resources/views/components/app-layout.blade.php: school identity and authorized audit link
- phpunit.xml: force isolated testing database configuration
- README.md and this phase report

## Authorization and behavior

Dashboard requires an active account in an active school; logout remains available without school assignment.
School identity derives from authentication, never request parameters. Context is cleared on success and failure.
User authentication uses globally unique emails; User is deliberately not globally school-scoped because login must resolve users before context exists.
RBAC checks permission names and school ownership; teacher-assignment restrictions arrive with the assignments module.
SchoolPolicy checks ownership plus school.view/school.update.
Audit listing requires audit.view and only queries the current school; it has no write/delete endpoints.
Role changes use a transaction, validation and an audit record; an audit failure rolls back the role change.
Provisioning is repeatable, logs its grants, and refuses to move an existing school account.

## Manual verification

1. Run `php artisan migrate`.
2. If needed, run `php artisan app:create-user`, entering your chosen account details.
3. Run `php artisan app:provision-school SCHOOL "Your School Name" your-email@example.com`, using that account email.
4. Run `npm run build` and `php artisan serve`.
5. Sign in at `/login`. Confirm the school's name appears and the Audit logs link is visible.
6. Open `/administration/audit-logs`; inspect SCHOOL_ADMIN_PROVISIONED and its before/after values.
7. Create a member with `php artisan app:create-user --school=SCHOOL`. Sign in with it in a private browser. Dashboard works; Audit logs is hidden and direct navigation returns 403.
8. Create an account without --school. Sign in; dashboard returns 403, but POST logout still works.
9. Automated tests cover two-school data isolation, foreign role rejection, inactive users/schools, context cleanup, policies, live permission revocation, audit redaction, update/delete rejection, and rollback on audit failure.

## Verification results

- 26 automated tests passed, 73 assertions, on the isolated MySQL test database.
- Production asset build, Pint, Blade compilation and whitespace checks passed.
- Additive migration applied successfully to school_erp.
- No application accounts were automatically assigned schools or administrator permissions.

## Limits and pending work

No school settings form or role-management UI is included in this foundation step.
No roles besides SCHOOL_ADMIN receive automatic permissions. Role editing interfaces must use AccessManager.
Provisioning may restore all SCHOOL_ADMIN permissions when deliberately rerun; it is a privileged console operation.
The scoped-model protections do not apply to raw SQL or explicit scope bypasses; database administrators can modify audit storage.
Published-result immutability, marks revision history, and module-specific auditing belong to their respective phases.
The local MySQL server version remains unchanged from Step 1 (8.0.46).
Browser layout has not been visually verified.

## Next recommended phase

School Details: extend the existing schools table, add authorized settings forms and validated logo uploads.
