# Step 4 — Academic Years (plan Phase 2)

## Existing functionality and completed work

Reused school context, school-scoped models, permission gates, policies, transactional auditing,
and the shared Blade/Tailwind layout. No packages were added or duplicated.

Added school-scoped academic-year CRUD, paginated listing, draft editing/removal, activation/current-year
selection, closure and archival. The sidebar includes Academics → Academic Years.
The header displays the actual current year's name, or “No current academic year”.

## Files created

- app/Enums/AcademicYearStatus.php
- app/Models/AcademicYear.php
- app/Policies/AcademicYearPolicy.php
- app/Http/Requests/SaveAcademicYearRequest.php
- app/Services/AcademicYearService.php
- app/Http/Controllers/AcademicYearController.php
- database/migrations/2026_10_27_120000_create_academic_years_table.php
- database/migrations/2026_10_27_121000_constrain_academic_year_current_flag.php
- resources/views/academics/years/index.blade.php
- resources/views/academics/years/form.blade.php
- resources/views/academics/years/show.blade.php
- tests/Feature/AcademicYearTest.php
- development/step-4.md

## Files modified

- bootstrap/app.php: resolve school context after authentication and before implicit route binding
- routes/web.php: academic-year resource and lifecycle routes
- app/Support/CurrentSchool.php: current academic-year lookup
- resources/views/components/app-layout.blade.php: academics navigation and current-year display
- README.md: progress and manual-use instructions

## Database changes

Created academic_years with id, school_id, name, start_date, end_date, is_current, status,
created_at, updated_at and deleted_at. School deletion is restricted by a foreign key.

- Unique school/name includes soft-deleted drafts, preserving historical identity.
- Composite unique school/id supports future ownership-safe academic relationships.
- School/status/start_date index supports school-scoped listing.
- Generated nullable current_school_id is school_id only when current; its unique index permits at most one current year per school.
- CHECK constraints enforce end_date > start_date, allowed lifecycle statuses, current implying ACTIVE and not deleted, and is_current being 0 or 1.
- The boolean check prevents MySQL tinyint values such as 2 from bypassing the generated uniqueness rule.

Mutations lock the parent school row inside a transaction before reading/changing years.
This serializes current-year switches, including when a school has no current year yet.
The unique index independently enforces the invariant against direct conflicting writes.

## Rules and assumptions

- New years start DRAFT and are not current. Users provide names/dates; there are no hard-coded current years.
- Only DRAFT years allow name/date edits or removal. Removal is a soft delete; there is no restore interface.
- Activate accepts DRAFT or ACTIVE and selects the year as current. A previous current year remains ACTIVE with its records, but loses current selection.
- Activating an already-current year is a no-op.
- ACTIVE → CLOSED clears current when necessary. Another current year is not automatically chosen.
- CLOSED → ARCHIVED preserves history. Closed/archived years cannot be reopened, edited or removed.
- Multiple ACTIVE years may exist, but at most one is current. Date overlap is allowed because the plan specifies no overlap restriction.
- End date must be strictly after start date; the year name is an arbitrary school-specific label up to 100 characters.
- Close/archive/delete use academic_year.update, following the existing permission catalog; activation uses academic_year.activate.
- Later modules must enforce these lifecycle restrictions for their own academic writes.

## Routes

All routes below are under /academics and require an active authenticated school account and academic_year.view.

- GET /academic-years: paginated list
- GET /academic-years/create and POST /academic-years: draft creation
- GET /academic-years/{academic_year}: details
- GET /academic-years/{academic_year}/edit and PUT/PATCH /academic-years/{academic_year}: draft editing
- DELETE /academic-years/{academic_year}: draft soft deletion
- POST /academic-years/{academic_year}/activate: activate/select current
- POST /academic-years/{academic_year}/close: close
- POST /academic-years/{academic_year}/archive: archive

## Authorization and auditing

AcademicYearPolicy checks ownership and the appropriate permission. Creation also requires academic_year.create;
updates/removal/close/archive require academic_year.update. School context runs before implicit route binding,
so foreign-school IDs return 404 for show, edit, update, delete and lifecycle endpoints.
The service also authorizes every mutation. Form Requests validate names/dates and prohibit submitted school,
status, current-selection and deletion fields.

Each real change has an audit entry containing before/after values, actor, school and timestamp.
Switching current years audits both the old selection and new selection. If audit creation fails, the whole
transaction rolls back. Application code uses the service; direct SQL/scope bypasses remain trusted infrastructure operations.

## Manual testing

1. Sign in as a provisioned school administrator and open Academics → Academic Years.
2. Click Add academic year; enter a session name and start/end dates. Create draft.
3. Open Edit draft, change its name/date and save. Try an invalid date range and duplicate name.
4. Click Activate & make current. Confirm ACTIVE status and the current-year name in the header.
5. Create and activate a second year. Verify only the second is current and the previous year remains ACTIVE.
6. Open the previous year and Make current; verify the selection switches back.
7. Expand Close academic year and confirm. Verify CLOSED and that the current flag clears when applicable.
8. Expand Archive academic year and confirm. Verify ARCHIVED and historical details remain visible.
9. Create a spare draft, remove it, and verify it leaves the list while the audit entry remains.
10. Open Audit logs and inspect creation, edits, current-year switches, closure, archival and removal.
11. A view-only account can read years, but cannot create, edit, select current, close, archive or delete.
12. Foreign-school IDs are tested automatically and must return 404.

## Verification and pending work

51 tests passed with 239 assertions. Tests run against school_erp_testing, not school_erp.
Production asset build, Pint, Blade compilation and changed-code whitespace checks passed.
Both additive migrations were applied to school_erp. No academic years were automatically created.
Coverage includes permissions, draft CRUD, invalid input, lifecycle transitions, cross-school endpoints,
current-year switching, per-school uniqueness, database constraints and audit rollback.
The original authentication, school settings and RBAC tests remain in the suite.
Browser appearance has not been visually verified. The local MySQL server version is unchanged.

## Next recommended phase

RBAC administration screens (plan Phase 3): users, roles and permissions, reusing the existing RBAC tables,
AccessManager service and audit infrastructure. Do not create a second RBAC implementation.
