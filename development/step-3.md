# Step 3 — School Details

## Completed

Settings → School Details allows authorized accounts to view and update school identity, contact information,
address and timezone, and upload a logo. The sidebar displays the current school's logo and name.
Existing school context, RBAC, SchoolPolicy and audit infrastructure are reused. No packages were installed.
There is no multi-school creation interface.

## Files created

- database/migrations/2026_10_27_110000_add_school_details.php
- app/Http/Requests/UpdateSchoolRequest.php
- app/Actions/UpdateSchoolDetails.php
- app/Http/Controllers/SchoolDetailsController.php
- resources/views/settings/school.blade.php
- resources/views/components/school-field.blade.php
- tests/Feature/SchoolDetailsTest.php
- development/step-3.md

## Files modified

- app/Models/School.php: allow school details attributes
- app/Services/AuditLogger.php: optional module name, retaining the administration default
- app/Http/Middleware/ResolveSchool.php: reload school data for each request
- routes/web.php: settings and logo routes
- resources/views/components/app-layout.blade.php: Settings navigation and school branding
- README.md: current progress, verification and next phase

## Database changes

Extended the existing schools table with nullable short_name, logo_path, affiliation_number, board,
email, phone, alternate_phone, address_line_1, address_line_2, city, state, country and postal_code.
Existing id, unique code, name, timezone, is_active and timestamps are reused.
The migration is additive, does not rewrite existing school records, and was applied to school_erp.
No duplicate school table or status column was created.

## Routes and authorization

- GET /settings/school (school.show): school.view and SchoolPolicy ownership check.
- PUT /settings/school (school.update): school.view plus school.update and ownership check.
- GET /school/logo (school.logo): active authenticated school account; branding is visible to all members of that school.

No route accepts a school ID. The current school derives from authentication.
The request rejects school_id, id, is_active and logo_path input. There are no create-school or deactivate-school controls.
View-only accounts see disabled fields without a Save button; update attempts return 403.
The update action independently checks authorization, locks the school row, and records before/after details in the same transaction.
Audit failure rolls back changes. Unchanged details do not create an audit record.

## Logo handling

PNG, JPEG and WebP only; MIME, extension, image content, 2 MB size and 2000×2000 pixel limits are checked.
SVG and arbitrary files are rejected. Server-generated filenames are stored on the private local disk,
under schools/{school_id}/logos. No public storage symlink is required.
The authenticated logo route resolves only the current school's stored file and sends private/no-store and nosniff headers.
Empty uploads preserve the existing logo. Failed updates remove the newly uploaded file.
Successful replacement retains the previous file and its audit path, preserving future historical-report references.
A retention/cleanup policy is still pending; these files are not automatically deleted.

## Verification

37 tests passed with 137 assertions on school_erp_testing. Coverage includes:

- Admin viewing/updating, audit before/after values, and read-only permissions.
- School code uniqueness, email/timezone validation and protected attributes.
- Foreign-school request and action denial.
- Private logo delivery, allowed image uploads, prohibited formats, size and dimensions.
- Logo replacement, retaining the current logo on empty upload, audit rollback and upload cleanup.
- Existing authentication, RBAC and school-isolation regression tests.

Production asset build, Pint, Blade compilation and changed-code whitespace checks passed.
The pre-existing plan.md whitespace change was left untouched.
Browser appearance has not been visually verified.

## Manual testing

1. Run php artisan serve and sign in with a provisioned school administrator.
2. Open Settings → School Details, or /settings/school.
3. Change name, board, contact details and address; save and verify the confirmation and updated values.
4. Upload a PNG/JPEG/WebP crest and verify it appears in settings and the sidebar after saving.
5. Open Audit logs and inspect SCHOOL_DETAILS_UPDATED, including old/new details and the actor.
6. Try an invalid email or oversized logo; verify validation messages and unchanged persisted data.
7. Save without a logo and verify the previous logo remains.
8. Test with school.view-only access: fields are disabled, Save is absent, and PUT is forbidden.
9. Test an unprivileged account: settings is hidden and direct navigation returns 403.

## Assumptions and pending work

School status uses the existing is_active flag. Self-deactivation is intentionally absent from school settings.
School timezone is saved as metadata; application-wide audit timestamps retain the configured Asia/Kolkata timezone.
Pre-login branding remains the original static prototype crest because no school context exists before login.
MySQL remains the existing local server version; this phase does not upgrade the server.
Full role-management UI and historical report snapshots belong to their planned phases.

## Next recommended phase

Academic Years: school-scoped CRUD, current-year invariant, activation/closure/archive transitions, authorization and tests.
