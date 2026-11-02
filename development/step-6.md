# Step 6 — Employee Management (plan Phase 4)

## Existing functionality and completed work

Employee tables/models did not exist. Reused school context, scoped-model protections, permission gates,
Form Requests, policies, transactional audit logging and the shared Blade/Tailwind interface.

Added Employees → Employee List, employee creation/detail/edit screens, search, type/status filters,
pagination, private photo uploads, and activation/deactivation. Employee types include Principal, Teacher,
Administrator, Accountant, Receptionist, Clerk, Librarian and Other. Creating an employee does not create
an application account, grant permissions or create a teacher profile.
No packages were installed.

## Files created

- database/migrations/2026_10_27_130000_create_employees_table.php
- app/Enums/EmployeeStatus.php
- app/Enums/EmployeeType.php
- app/Models/Employee.php
- app/Policies/EmployeePolicy.php
- app/Http/Requests/SaveEmployeeRequest.php
- app/Http/Requests/EmployeeIndexRequest.php
- app/Services/EmployeeService.php
- app/Http/Controllers/EmployeeController.php
- app/Support/RasterImageRules.php
- database/factories/EmployeeFactory.php
- resources/views/components/employee-field.blade.php
- resources/views/employees/index.blade.php
- resources/views/employees/form.blade.php
- resources/views/employees/show.blade.php
- tests/Feature/EmployeeTest.php
- development/step-6.md

## Files modified

- routes/web.php: employee resource, photo and status routes
- resources/views/components/app-layout.blade.php: employee navigation
- app/Http/Requests/UpdateSchoolRequest.php: reuse the shared raster-image rules, preserving logo validation behavior
- README.md: implementation progress and test totals

## Database changes

Created employees with id, school_id, employee_number, first_name, middle_name, last_name, employee_type,
email, phone, alternate_phone, gender, date_of_birth, joining_date, designation, department, address,
photo_path, status and timestamps.

- School foreign key restricts deleting a referenced school.
- Unique school/employee_number; numbers are supplied by users and can contain separators such as '/'.
- Unique school/id supports later ownership-safe relationships.
- Indexes cover school/status/name ordering and school/type filtering.
- CHECK constraints validate controlled status/type/gender values and joining_date after a supplied birth date.
- ACTIVE/INACTIVE statuses; no hard deletion or employee deletion route.

The additive migration was applied to school_erp. No employee records or accounts were automatically created.

## Routes

All routes require an active authenticated school account and employee.view.

- GET /employees: searchable listing, 20 records per page
- GET /employees/create and POST /employees: creation
- GET /employees/{employee}: detail
- GET /employees/{employee}/edit and PUT/PATCH /employees/{employee}: editing
- POST /employees/{employee}/activate: activation
- POST /employees/{employee}/deactivate: deactivation
- GET /employees/{employee}/photo: authenticated private photo delivery

Search covers full name, employee number, email and department. Status and employee-type filters are combinable.
Pagination retains filters. The valid search string '0' is treated as a search rather than an empty value.

## Authorization and validation

EmployeePolicy requires employee.view to read; creation also requires employee.create; updates and status
actions also require employee.update. The existing permission catalog already contains these permissions.
Scoped implicit model binding returns 404 for foreign-school employee IDs, including photo and status routes.
The service independently checks ownership/permissions before file storage and again after locking the school.
It re-reads the acting account's permissions so revoked access is not accepted from a stale model instance.

Required fields are employee_number, first_name, employee_type, joining_date and aadhaar_number. Other fields are optional,
including last_name to support people with a single name. Employee email is not a login identifier and is not
required to be unique. Dates use YYYY-MM-DD; birth dates cannot be future or on/after the joining date.
Future joining dates are allowed. No minimum age or phone-country format is imposed by this phase.
Submitted school_id, status, photo_path, id and user_id are prohibited. Status changes use explicit actions.
Inactive employee details remain editable for corrections.

## Photos and auditing

RasterImageRules centralizes the unchanged image-upload limits for employee photos and school logos:
PNG/JPEG/WebP, verified file content/MIME/extension, at most 2 MB and 2000×2000 pixels. SVG is rejected.
Photos use generated filenames on the private local disk under schools/{school_id}/employees/photos.
The photo route requires employee.view plus ownership and sends private/no-store and nosniff headers.
No public storage link is required. Empty uploads preserve the current photo.

Employee mutations lock the school row and use database transactions. Number uniqueness is checked again
under that lock as well as by the database. EMPLOYEE_CREATED, EMPLOYEE_UPDATED, EMPLOYEE_ACTIVATED and
EMPLOYEE_DEACTIVATED audit events capture before/after details, actor, school and timestamps.
Audit failures roll back record changes and remove newly uploaded photos. No-op saves/status changes do not
create duplicate audit events. Previous photos are retained privately when replaced; a retention/cleanup
policy remains pending, matching the existing school-logo history approach.

## Manual testing

1. Sign in as a school administrator. Open Employees → Employee List, or /employees.
2. Click Add employee. Enter an employee number, first name, type and joining date. Optionally add contact,
   personal, designation/department and address details. Save and review the record.
3. Open Edit employee, correct details, upload a valid photo and save. Verify the photo on the detail page.
4. Search by full name, number or department; combine employee-type and status filters, then Reset.
5. Create enough records to paginate and verify filters remain applied while changing pages.
6. Expand Deactivate employee and confirm. Verify INACTIVE, retained details and visibility through filters.
   Reactivate and verify ACTIVE. Employee status does not change login-account status because account linkage
   is not yet implemented.
7. Try a duplicate number, invalid email, future birth date, or birth date after joining. Verify validation errors.
8. Try an SVG, oversized image or image wider/taller than 2000 pixels; verify upload rejection. Save without
   a photo to confirm the current one is retained.
9. Use Administration → Roles to grant employee.view only to a test role, then assign it to a test account.
   Verify it can view records/photos but has no Add/Edit/status controls and cannot mutate endpoints.
10. Inspect Audit logs for employee creation, updates and activation changes.

## Verification

81 tests passed with 454 assertions against the isolated school_erp_testing database.
New tests cover creation/detail/edit, no automatic login creation, required/protected fields, date validation,
per-school numbers and database uniqueness, activation/deactivation, read-only permissions, cross-school
URLs/service calls, search/filter/pagination behavior, private photo delivery, image validation/replacement,
and audit-failure rollback/upload cleanup. School-logo regression tests still pass after shared validation extraction.
Production asset build, Pint, Blade compilation and changed-code whitespace checks passed.
Browser appearance has not been visually verified.

## Assumptions and pending work

Employee status and application-account status are separate. No employee-user linkage, teacher profile,
teacher assignment, payroll or attendance workflow is included. Employees are not implicitly teachers,
and choosing the Teacher type does not grant teacher permissions. Previously uploaded photos remain private
but require a future retention policy. The local MySQL version is unchanged.

## Next recommended phase

Teacher Profiles (plan Phase 5): extend employee records with one teacher profile per employee,
qualification/specialization/experience/status, authorization, auditing and tests.

## Employee form redesign

Reviewed `CLAUDE.md` before implementation. Add/Edit Employee now uses the full workspace width with shared form cards for personal details, employment details, optional contact information and an optional photo. Wide screens place the cards in two columns; phones stack them with clear Save/Cancel actions. Existing field names, required inputs, validation rules, permissions, private-photo delivery and save endpoints are preserved. The shared Edit form uses the same presentation.

Fields reuse the existing styled field component, with autocomplete hints and associated error messages. Gender, employee type, address and photo now include inline errors. Date of birth retains its server rule and adds a matching maximum date in the browser. Photo selection provides an immediate local preview, restoring the current portrait or placeholder when cleared, reset or unable to load; image validation remains on the server. The preview script releases temporary object URLs and introduces no dependency. No backend or database changes were required.

Verification: **22 employee/photo tests / 186 assertions passed** on isolated `school_erp_testing`. Production build, Blade compilation, JavaScript syntax and whitespace checks passed. Chromium checked Create/Edit, retained input after validation errors, required fields, accessible errors, photo preview/reset/fallback, original form payload and horizontal overflow at 320, 390, 768, 1024, 1440 and 1920 pixels. Fixtures were captured in a rolled-back test transaction; browser requests were intercepted and performed no writes.

Previews: [desktop employee form](previews/employee-create-1440.png), [mobile employee form](previews/employee-create-390.png).

## Aadhaar identification

Employees and students require `aadhaar_number` in their Add/Edit forms. The shared `AadhaarNumber` rule checks exactly 12 ASCII digits without spaces in both requests and services. Validation checks the format only. Existing permissions govern editing. Missing, empty and invalid values are rejected on creation and editing, including direct service calls. Existing records without Aadhaar must supply one when next saved. The student field is always visible in Student details rather than inside the optional personal-details disclosure. The nullable text columns use Laravel's encrypted cast and are excluded from model JSON. Profiles display `XXXX XXXX` followed by the final four digits; authorized editors see the full number in the edit form. AuditLogger redacts old/new Aadhaar values recursively. Keep the application's encryption key with protected backups, as it is needed to decrypt these values.

Migration `2026_11_02_080000_add_aadhaar_number_to_employees_and_students.php` is applied to the application database. Existing records remain null. No identification documents are uploaded by this change.

Initial implementation verification (before mandatory enforcement): **122 related tests / 978 assertions passed** across identification, employee/student, enrollment/departure/progression, audit and guardian editors. The 18 identification tests cover encryption, audit redaction, masked profiles, hidden JSON, optional/invalid values, edit/clear/preserve behavior (before mandatory enforcement), no-op saves, validation recovery, permissions, school scope and rollback. Chromium checked optional inputs, numeric format validation, clearing, restored drafts/errors and masked profiles at 320, 390, 768 and 1440 pixels. Production build, Blade compilation, targeted Pint, PHP syntax and whitespace checks passed. Browser fixtures used rolled-back testing transactions and intercepted requests.


Mandatory Aadhaar verification: **121 related tests / 1,000 assertions passed**. Missing, null, blank and invalid values are rejected for creation and editing, including older records and direct service calls. Valid updates, encryption, masked profiles, audit redaction, guardian edits and enrollment/departure saves remain covered. The required student input sits in the always-visible Student details grid. Existing null database values are retained until users supply a number on their next edit; no new migration or automatic data population is needed. Browser checks passed at 320, 390, 768 and 1440 pixels for required inputs, blank rejection, errors, restored drafts and masked profiles.
