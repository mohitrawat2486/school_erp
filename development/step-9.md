# Step 9 — Sections (plan Phase 7)

## Existing functionality and completed work

Classes, school context, section.view/section.manage permissions, policies and transactional auditing existed.
There was no Section model/table. This phase reuses those foundations and shared Blade components.

Added Academics → Sections with class-linked section creation, details/editing, optional capacity,
activation/deactivation, search, status/class filters, and paginated class pickers.
Class detail pages link to their sections and offer section creation when authorized.
No packages were installed or duplicate class records introduced.

## Files created

- database/migrations/2026_10_27_160000_create_sections_table.php
- app/Enums/SectionStatus.php
- app/Models/Section.php
- app/Policies/SectionPolicy.php
- app/Services/SectionService.php
- app/Http/Requests/SaveSectionRequest.php
- app/Http/Requests/SectionIndexRequest.php
- app/Http/Requests/SelectSectionClassRequest.php
- app/Http/Controllers/SectionController.php
- database/factories/SectionFactory.php
- resources/views/sections/index.blade.php
- resources/views/sections/select-class.blade.php
- resources/views/sections/form.blade.php
- resources/views/sections/show.blade.php
- tests/Feature/SectionTest.php
- development/step-9.md

## Files modified

- app/Models/SchoolClass.php: sections relationship with explicit class_id foreign key
- app/Services/SchoolClassService.php: transactional section deactivation when a class is deactivated
- resources/views/classes/show.blade.php: section links and class status-action explanation
- resources/views/components/app-layout.blade.php: Sections navigation and Academics heading visibility
- routes/web.php: section resource, class picker and lifecycle routes
- README.md: current progress, verification and next phase

## Database changes

Created sections with id, school_id, class_id, name, nullable capacity, status and timestamps.

- Unique school/class/name prevents duplicate section names within a class, including inactive sections.
- Composite school_id/class_id foreign key references classes school/id and prevents cross-school class links.
- School/class deletion is restricted while referenced.
- Unique school/class/id supports future section/class consistency constraints in enrollments and assignments.
- Index school/status/class supports school-scoped filtered queries.
- Capacity is an optional unsigned small integer. CHECK capacity IS NULL OR capacity > 0 rejects zero.
- Status defaults ACTIVE, with a CHECK constraint limiting values to ACTIVE/INACTIVE.

The additive migration was applied to school_erp. No sections were automatically created or granted permissions.

## Routes

All section routes require an active authenticated school account and section.view.

- GET /academics/sections: listing/search/status/class filters
- GET /academics/sections/classes: searchable class picker for filtering, including inactive classes
- GET /academics/sections/create: searchable active-class picker for creation
- GET /academics/sections/create?class_id={id}: section form for a selected active class
- POST /academics/sections: creation
- GET /academics/sections/{section}: detail
- GET /academics/sections/{section}/edit and PUT/PATCH /academics/sections/{section}: editing
- POST /academics/sections/{section}/activate: activation
- POST /academics/sections/{section}/deactivate: deactivation

There is no deletion route. Lists and pickers paginate at 20 records and preserve filters.
Section listing eagerly loads classes, avoiding N+1 queries. Search covers section name and class name/code.
The class pickers follow the class display order; sections list by class ID, section name and ID.

## Authorization and rules

SectionPolicy requires section.view to read and additionally section.manage to create/edit/change status.
Section management is independent of class.manage and class.view. Section screens expose the minimum class
identity required to select/display sections; full class-record links require SchoolClassPolicy authorization.
Scoped model binding returns 404 for foreign-school section IDs. Class picker and submitted class references
are also school-scoped. A foreign-class list filter fails validation.

The service independently authorizes the fresh actor, locks the school and relevant records, checks the parent
class, and rechecks uniqueness. Composite foreign keys and unique indexes independently protect ownership/identity.

Names are required strings up to 50 characters. A name such as A may appear in multiple classes or schools,
but not twice within one class. Optional capacity must be an integer from 1 to 65535; empty means unknown.
Submitted school_id, id, status and academic_year_id are prohibited. The linked class is fixed after creation;
class_id is prohibited in edits to preserve historical section identity. Inactive records remain editable for corrections.

## Lifecycle and auditing

Creation/activation requires an active parent class. Deactivating a section retains its records and leaves
the class active. Activating a section never activates its parent class automatically.

Class deactivation also deactivates its active sections in the same transaction. Children are processed in
batches of 100 using chunkById, retaining atomicity without loading all children at once.
Each child receives SECTION_DEACTIVATED with reason class_inactive, followed by the class audit event.
This cascade is authorized by class.manage; a class administrator need not separately hold section.manage.
Reactivating a class leaves its sections inactive until explicitly reactivated.

Section::eligible() and isEligible() require both active section and active parent class for later enrollment/
assignment workflows. Raw SQL is a trusted infrastructure path and does not perform application cascades.

SECTION_CREATED, SECTION_UPDATED, SECTION_ACTIVATED and SECTION_DEACTIVATED capture before/after class ID,
name, capacity and status plus actor, school and timestamps. Mutations and audits share a transaction.
Audit failure rolls back section changes; class-cascade audit failure rolls back parent/children/audits together.
No-op saves/status changes do not create duplicate audit events.

## Manual testing

1. Sign in as a school administrator. Create an active base class if needed.
2. Open Academics → Sections → Add section, search/select a class, enter A and an optional capacity, then save.
3. Edit the section name/capacity. Verify the linked class stays fixed.
4. Create section A in another class. Verify it succeeds; a second A in the same class must fail.
5. Try zero, negative, fractional or oversized capacity; verify validation. Clear capacity and verify Not set.
6. Use Choose class, search and status filters; verify pagination retains the selected class and query.
7. Deactivate/reactivate an individual section and verify class status remains active.
8. Deactivate the parent class. Verify its active sections become INACTIVE and cannot be activated yet.
9. Reactivate the class and verify its sections remain inactive. Explicitly reactivate a section.
10. Inspect Audit logs for section edits and both parent/child status changes.
11. Grant section.view only to a test account. Verify it can view/filter sections but cannot create/edit/change status.
12. Grant section.view + section.manage without class permissions. Verify section management works while class
    management remains unavailable.

## Verification

128 tests passed with 786 assertions against school_erp_testing.
New coverage includes creation/editing and class reuse, per-class uniqueness, capacity/null validation,
immutable parent linkage, active/scoped pickers, read-only permissions, independent section management,
foreign routes/filters/service calls, search/filter/pagination, activation/cascade behavior, 101-section batch
processing, database uniqueness/ownership/capacity constraints, effective eligibility and audit rollback.
Prior authentication, school, RBAC, academic-year, employee, teacher and class tests remain passing.
Production asset build, Pint, Blade compilation and changed-code whitespace checks passed.
Browser appearance has not been visually verified.

## Assumptions and pending work

Sections are persistent school/class records rather than academic-year allocations, matching the plan.
No student enrollment or seat-occupancy workflow exists yet; capacity is stored for the enrollment phase.
History is retained through deactivation instead of physical deletion. Parent moves are not exposed.
Class and section status propagation is implemented in application services, not database triggers.
The local MySQL server version is unchanged.

## Next recommended phase

Subjects and year-specific Class Subjects (plan Phase 8): school subject catalog and academic-year/class
allocations, marks limits, authorization, audit logging and tests.
