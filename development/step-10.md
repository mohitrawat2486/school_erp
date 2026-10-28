# Step 10 — Subjects and Class Subjects (plan Phase 8)

## Existing functionality and completed work

Academic years, classes, school context, subject.view/subject.manage permissions and transactional audit
logging existed. Neither subject table existed. The prototype uses Core and Optional subject types;
this phase follows that catalog convention and the existing Blade/Tailwind interface.

Added Academics → Subjects and Class Subjects:

- School subject catalog with name/code/type, search/status/type filters and activation controls.
- Academic-year/class-specific subject allocations with theory/practical maximums and passing marks.
- Paginated year → class → subject selection, allocation search/filtering and historical read-only screens.
- Exact decimal validation, ownership constraints, lifecycle checks and audit events.

No packages were installed. No grading, marks entry or results were implemented in this phase.

## Files created

- database/migrations/2026_10_27_170000_create_subjects_and_class_subjects.php
- app/Enums/SubjectStatus.php, SubjectType.php, ClassSubjectStatus.php
- app/Models/Subject.php, ClassSubject.php
- app/Policies/SubjectPolicy.php, ClassSubjectPolicy.php
- app/Services/SubjectService.php, ClassSubjectService.php
- app/Support/MarksConfiguration.php
- app/Http/Requests/SaveSubjectRequest.php, SubjectIndexRequest.php
- app/Http/Requests/SaveClassSubjectRequest.php, ClassSubjectIndexRequest.php, SelectClassSubjectRequest.php
- app/Http/Controllers/SubjectController.php, ClassSubjectController.php
- database/factories/SubjectFactory.php, ClassSubjectFactory.php
- resources/views/subjects/index.blade.php, form.blade.php, show.blade.php
- resources/views/class-subjects/index.blade.php, select.blade.php, form.blade.php, show.blade.php
- tests/Feature/SubjectModuleTest.php
- development/step-10.md

## Files modified

- app/Models/AcademicYear.php and SchoolClass.php: classSubjects relationships
- app/Services/AcademicYearService.php: block removing a draft year with retained allocations
- routes/web.php: subject/allocation resource, selection and status routes
- resources/views/components/app-layout.blade.php: subject/allocation navigation
- README.md: implementation progress and verification totals

## Database changes

Created subjects with id, school_id, code, name, type, status and timestamps.
Codes are unique within a school, including inactive records. Names may repeat with different codes.
Type is CORE/OPTIONAL; status is ACTIVE/INACTIVE. School deletion is restricted while referenced.
Unique school/id and school/status/name index support ownership-safe relationships and queries.

Created class_subjects with id, school_id, academic_year_id, class_id, subject_id,
theory_max_marks, practical_max_marks, passing_marks, status and timestamps.

- Unique school/year/class/subject retains one allocation identity, including inactive allocations.
- Composite school/parent foreign keys reference academic years, classes and subjects, preventing mixed-school links.
- Unique school/year/class/id and school/id support future context-safe exam/assignment references.
- Filter index covers school/status/year/class.
- Marks use nullable DECIMAL(6,2); CHECK constraints reject negative values, zero combined configured maximum,
  passing marks without a maximum, passing marks above the combined maximum, and unknown status.

The additive migration was applied to school_erp. No subjects, allocations or permissions were automatically created.

## Routes

All routes below require an active authenticated school account and subject.view.

Subject catalog:

- GET /academics/subjects: listing/search/status/type filters
- GET /academics/subjects/create and POST /academics/subjects: creation
- GET /academics/subjects/{subject}: detail
- GET /academics/subjects/{subject}/edit and PUT/PATCH /academics/subjects/{subject}: editing
- POST /academics/subjects/{subject}/activate or /deactivate: status actions

Class allocations:

- GET /academics/class-subjects: listing/search/year/class/subject/status filters
- GET /academics/class-subjects/filter: paginated year/class filter selection
- GET /academics/class-subjects/create: paginated year → class → subject creation selector
- GET /academics/class-subjects/create?academic_year_id={id}&class_id={id}&subject_id={id}: marks form
- POST /academics/class-subjects: allocation creation
- GET /academics/class-subjects/{class_subject}: detail
- GET /academics/class-subjects/{class_subject}/edit and PUT/PATCH /academics/class-subjects/{class_subject}: marks settings
- POST /academics/class-subjects/{class_subject}/activate or /deactivate: status actions

There are no deletion routes. Listings/selectors paginate at 20 records and preserve filters.
Allocation lists eagerly load all three parent records. The subject picker excludes already allocated subjects
for the selected year/class; use the existing allocation to reactivate or correct settings.

## Authorization and ownership

Both policies require subject.view to read and additionally subject.manage for mutations.
Management does not require broad year/class administration permissions; selectors expose the minimum identity
needed to allocate subjects. Parent selection and filter queries are school-scoped, and foreign IDs are rejected.
Scoped route binding returns 404 for foreign subjects/allocations. Composite foreign keys independently enforce ownership.
Services authorize a fresh persisted actor, lock the school and parents/target row, and recheck constraints.

Subject form validation requires name/code/type and prohibits school/status/ID injection.
Allocation form ownership links are immutable after creation; updates prohibit year/class/subject ID fields.
Class-subject marks rules are shared between Form Requests and the service rather than duplicated.

## Marks configuration

Theory/practical maximums and passing marks are optional. All three may be NULL for later configuration.
Each supplied value is 0–9999.99 with at most two decimal places. Configured components must total more than zero.
Passing marks require a configured total and may equal, but never exceed, that total. A zero passing threshold is allowed.
One component may be absent, supporting theory-only or practical-only assessments.

Validation and maximum display use scaled integer hundredths, avoiding binary floating-point comparisons.
For example, 0.10 + 0.20 permits exactly 0.30. Values such as 1.001 are rejected rather than silently rounded.
Blank form fields clear configuration values; omitted fields in a partial update retain existing values and are
validated against the complete resulting configuration. Actual exam/subject component pass rules arrive later.

## Academic-year and parent lifecycle

Allocation creation requires a DRAFT or ACTIVE academic year, active class and active subject.
Existing allocations can be edited/deactivated only while the year is DRAFT or ACTIVE.
Activation additionally requires active class/subject. CLOSED and ARCHIVED years are read-only for all allocation mutations.
A draft academic year with any retained allocation cannot be removed, preventing soft-deleted parent history.

Allocations start ACTIVE even during draft-year planning; teaching eligibility separately requires an ACTIVE year.
ClassSubject::eligible() and isEligible() check active allocation, academic year, class and subject.
Subject/class deactivation changes effective availability without rewriting allocation settings or historical year data.
Reactivation restores availability to otherwise eligible active allocations. Explicitly inactive allocations stay inactive.
Year closure also makes allocations unavailable while preserving their status/settings for history.

Core/Optional is catalog metadata in this phase; no student elective selection or grading rules are inferred.
Subjects are reusable across classes and years; class-specific marks never live on the global subject record.
Published result snapshots will preserve subject identity/grading configuration in the planned results phases.

## Auditing and manual testing

SUBJECT_CREATED/UPDATED/ACTIVATED/DEACTIVATED and CLASS_SUBJECT_CREATED/UPDATED/ACTIVATED/DEACTIVATED
record before/after values with actor, school and timestamp. Changes and audit records share a transaction;
audit failures roll back the mutation. No-op saves/status changes do not duplicate audits.

1. Sign in as a school administrator. Open Academics → Subjects and create ENG / English / Core.
2. Edit its name/type, search/filter the catalog and inspect its status actions.
3. Ensure a draft or active academic year and an active class exist. Open Class Subjects → Allocate subject.
4. Select the year, class and subject. Enter 70 theory, 30 practical and 33 passing marks. Save; verify total 100.00.
5. Allocate the same subject to another year/class. A second allocation for the same year/class/subject must fail.
6. Edit limits. Try negative values, more than two decimal places, zero combined maximum, and passing > total.
   Verify validation errors. Try all blank fields and a theory-only configuration.
7. Use Choose year/class, query/status filters and the subject's View class allocations link.
8. Deactivate a subject; its allocations retain settings but become unavailable. Reactivate it and verify eligibility.
9. Close the year. Verify historical allocation details remain readable but edit/activation/deactivation are blocked.
10. Attempt to remove a draft year that has an allocation; verify it is retained with a validation message.
11. Grant subject.view only to a test role/account; verify read-only access. Grant subject.manage to test changes
    independently of class/year administration permissions.
12. Inspect Audit logs for subject and allocation changes.

## Verification and pending work

146 tests passed with 965 assertions against school_erp_testing. Coverage includes catalog CRUD/status/validation,
year-specific allocation uniqueness and immutable context, decimal boundaries and NULL configuration, partial updates,
closed/archived-year protection, draft-year retention, inactive parents, effective eligibility, read-only access,
independent management permissions, foreign routes/parents/filters/services, pagination, database ownership/marks
constraints and audit rollback. Prior module regression tests remain passing.
Production asset build, Pint, Blade compilation and changed-code whitespace checks passed.
Browser appearance has not been visually verified. The local MySQL server version is unchanged.
No default subject data, teacher assignments, exams, marks entry or result calculations were created.

## Next recommended phase

Teacher Assignments (plan Phase 9): academic-year-specific class/section/subject assignments,
teacher eligibility and assignment-based authorization foundations.
