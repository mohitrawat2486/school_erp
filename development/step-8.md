# Step 8 — Classes (plan Phase 6)

## Existing functionality and completed work

The repository had no class table/model. School context, class.view/class.manage permissions,
policies, audit logging and shared Blade/Tailwind components already existed and are reused.
The customer prototype combines classes and sections; this phase implements the class portion.

Added Academics → Classes: paginated listing, search by name/code, status filter, creation, details,
editing, numeric display order, activation and deactivation. No packages were installed.
Sections, student counts and academic-year allocations are not fabricated or automatically created.

## Files created

- database/migrations/2026_10_27_150000_create_classes_table.php
- app/Enums/ClassStatus.php
- app/Models/SchoolClass.php
- app/Policies/SchoolClassPolicy.php
- app/Http/Requests/SaveSchoolClassRequest.php
- app/Http/Requests/SchoolClassIndexRequest.php
- app/Services/SchoolClassService.php
- app/Http/Controllers/SchoolClassController.php
- database/factories/SchoolClassFactory.php
- resources/views/classes/index.blade.php
- resources/views/classes/form.blade.php
- resources/views/classes/show.blade.php
- tests/Feature/SchoolClassTest.php
- development/step-8.md

## Files modified

- app/Models/School.php: classes relationship
- routes/web.php: class resource and lifecycle routes
- resources/views/components/app-layout.blade.php: Classes link and shared Academics heading
- README.md: current progress, verification and next phase

## Database changes

Created classes with id, school_id, name, code, sort_order, status and timestamps.
The PHP model is named SchoolClass because Class is a reserved PHP keyword; it explicitly maps to classes.
No school_classes table is created.

- School foreign key restricts deleting a referenced school.
- Names and codes each have a separate unique school/field constraint, including inactive records.
- Unique school/id supports later same-school class/section/enrollment relationships.
- Indexes support school/order/name and school/status/order queries.
- sort_order is an unsigned integer with default 0; lower values come first.
- Status defaults ACTIVE, with a CHECK constraint limiting values to ACTIVE/INACTIVE.

The additive migration was applied to school_erp. No classes were automatically seeded or changed.

## Routes

All routes require an active authenticated school account and class.view.

- GET /academics/classes: listing/search/status filter
- GET /academics/classes/create and POST /academics/classes: creation
- GET /academics/classes/{school_class}: detail
- GET /academics/classes/{school_class}/edit and PUT/PATCH /academics/classes/{school_class}: editing
- POST /academics/classes/{school_class}/activate: activation
- POST /academics/classes/{school_class}/deactivate: deactivation

There is no deletion route. Listing is paginated at 20 records and retains search/status filters.
Classes sort numerically by sort_order, then by name and id for deterministic results.
The search value '0' is handled as a valid query.

## Authorization and validation

SchoolClassPolicy requires class.view to read and additionally class.manage to create/edit/change status.
Class routes are independent of academic_year.view. View-only users can inspect classes but cannot mutate them.
Scoped model binding returns 404 for foreign-school class IDs. The service independently checks the fresh
actor's persisted permissions, locks the school and class, and enforces ownership before writing.

Names are required strings up to 100 characters. Codes are required up to 50 characters, with letters,
numbers, dashes or underscores. Uniqueness is scoped to the current school and rechecked under the school
lock; database unique indexes independently prevent duplicates.

Display order is a required integer from 0 to 4294967295; ties are allowed. The form explains the order.
Submitted school_id, id, status, academic_year_id and section_id are prohibited. Status changes use explicit actions.
Inactive class details remain editable for corrections. Deactivation preserves the record and its identity.

## Audit behavior

CLASS_CREATED, CLASS_UPDATED, CLASS_ACTIVATED and CLASS_DEACTIVATED contain before/after name, code,
sort order and status, plus actor, school and timestamp metadata. Changes and audits share a transaction.
Audit failure rolls back creation, edits or status changes. Repeating an unchanged status action or save
is a no-op and does not duplicate the audit event.

## Manual testing

1. Sign in as a school administrator. Open Academics → Classes, or /academics/classes.
2. Click Add class. Enter a base name such as Nursery or 5, a unique code and a display order. Save.
3. Open Edit class and change its name/code/order; verify the detail screen and listing update.
4. Create several classes with orders 0, 2 and 10; verify numeric ordering. Give two the same order and
   verify they are sorted by name. Keep sections such as A/B separate from the class name.
5. Search by class name/code, apply the status filter and Reset. Pagination retains filters.
6. Try duplicate names/codes, negative or fractional order, and a code containing spaces; verify validation.
7. Expand Deactivate class and confirm. Verify INACTIVE while retaining details and history. Reactivate it.
8. Use Administration → Roles to grant class.view only to a test account. Verify it can read classes
   but sees no create/edit/status controls and cannot mutate endpoints.
9. Grant class.view + class.manage to a test account without academic-year permissions. Verify class
   management works independently.
10. Inspect Audit logs for class creation, edits and status changes.

## Verification

110 tests passed with 664 assertions against school_erp_testing.
New tests cover class CRUD behavior, audit snapshots, required/protected fields, numeric order validation,
school-specific name/code uniqueness including inactive records, numeric sorting/ties, search/status filters,
pagination, view-only access, independent class-management permissions, cross-school routes/service calls,
activation/deactivation, retained records, database constraints and rollback on audit failure.
Prior authentication, RBAC, school settings, academic years, employee and teacher tests remain passing.
Production asset build, Pint, Blade compilation and changed-code whitespace checks passed.
Browser appearance has not been visually verified.

## Assumptions and pending work

Classes are persistent school master records; year-specific subjects/enrollments will reference them in later phases.
No class/year link, sections or student placement are stored here. Names and codes remain reserved while inactive.
Class names are user-defined; the form guides users to enter base classes rather than combining class and section.
No physical deletion is exposed. Later sections/assignments/enrollment workflows must check parent class activity.
The local MySQL server version remains unchanged.

## Next recommended phase

Sections (plan Phase 7): school-scoped sections linked to classes, name uniqueness within class,
capacity, status, authorization, audit logging and tests.
