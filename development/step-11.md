# Step 11 — Teacher Assignments (plan Phase 9)

## Existing functionality and completed work

Reused academic years, teacher profiles/employees, classes, sections, class subjects, school context,
teacher_assignment.view/manage permissions, RBAC and transactional audit logging.
User accounts had no employee identity link, so permission checks alone could not resolve a signed-in teacher.

Added Academics → Teacher Assignments with paginated selection/listing, class/subject teacher responsibilities,
whole-class or specific-section scopes, activation/deactivation, conflict detection and read-only history.
Added an explicit employee-account link under Administration → Users.
Added reusable assignment-aware marks authorization checks for the later marks workflow.
No packages were installed; no marks-entry or student module was implemented.

## Files created

- database/migrations/2026_10_27_180000_create_teacher_assignments_and_account_links.php
- app/Enums/AssignmentType.php, TeacherAssignmentStatus.php
- app/Models/TeacherAssignment.php
- app/Policies/TeacherAssignmentPolicy.php
- app/Services/TeacherAssignmentService.php
- app/Services/TeacherAssignmentAccess.php
- app/Services/EmployeeAccountLinker.php
- app/Http/Requests/StoreTeacherAssignmentRequest.php
- app/Http/Requests/AssignmentIndexRequest.php
- app/Http/Requests/SelectAssignmentRequest.php
- app/Http/Requests/EmployeeAccountRequest.php
- app/Http/Controllers/TeacherAssignmentController.php
- app/Http/Controllers/EmployeeAccountController.php
- database/factories/TeacherAssignmentFactory.php
- resources/views/assignments/index.blade.php, types.blade.php, select.blade.php, form.blade.php, show.blade.php
- resources/views/administration/users/employee.blade.php
- tests/Feature/TeacherAssignmentTest.php
- development/step-11.md

## Files modified

- app/Models/AcademicYear.php, TeacherProfile.php, SchoolClass.php: assignment relationships
- app/Models/User.php: employee relationship
- app/Models/Employee.php: account relationship
- app/Services/AcademicYearService.php: retain years with teacher assignments, even without subject allocations
- app/Http/Requests/SaveManagedUserRequest.php: prohibit changing employee identity through general account edits
- routes/web.php: assignment and employee-account link endpoints
- resources/views/components/app-layout.blade.php: Teacher Assignments navigation
- resources/views/administration/users/form.blade.php: Link employee account action
- resources/views/teachers/show.blade.php: filtered teacher-assignment link
- README.md: progress, setup and verification totals

## Database changes

Added nullable, unique users.employee_id with a composite school/employee foreign key. One employee can link
to one account, including inactive accounts; unlinked accounts remain valid. A CHECK requires school_id whenever
employee_id is present. The migration does not automatically link any existing accounts or match emails.

Created teacher_assignments with id, school_id, academic_year_id, teacher_id, class_id, nullable section_id,
nullable subject_id, nullable class_subject_id, assignment_type, status and timestamps.
Teacher IDs reference teacher_profiles rather than employees or users. class_subject_id is derived by the
service from the selected year/class/subject; it is not accepted from the browser.

- Composite school/parent keys enforce same-school year, teacher and class references.
- Composite school/class/section key prevents sections from a different class or school.
- Composite school/year/class/subject/class_subject key enforces the exact curriculum allocation.
- Added the corresponding unique reference key to class_subjects.
- CHECK constraints enforce ACTIVE/INACTIVE and assignment shape: CLASS_TEACHER has no subject/curriculum;
  SUBJECT_TEACHER requires both.
- Generated section_key/subject_key map NULL to a zero sentinel for unique identity indexing. This prevents
  duplicate assignments even when section/subject is NULL. The sentinel is not an assumed business record ID.
- Unique school/year/teacher/class/type/scope identity includes inactive assignments.
- Generated active_class_teacher_section plus a unique index prevents two active class teachers in the exact
  same school/year/class/section scope, including whole-class scope.
- Whole-class versus individual-section overlap is additionally checked by the service under a school row lock.
- Indexes support teacher access checks and year/class/status listing. Referenced records are not cascade-deleted.

The additive migration was applied to school_erp. No assignments, links, roles or permissions were seeded.

## Routes

Assignments require an active authenticated school account and teacher_assignment.view:

- GET /academics/teacher-assignments: paginated listing/search/type/status/year/class/teacher filters
- GET /academics/teacher-assignments/filter: paginated year/class filter selection
- GET /academics/teacher-assignments/create: type → year → teacher → class → scope → subject selector
- POST /academics/teacher-assignments: create
- GET /academics/teacher-assignments/{teacher_assignment}: scope/status/detail
- POST /academics/teacher-assignments/{teacher_assignment}/activate or /deactivate: lifecycle actions

Changes additionally require teacher_assignment.manage. There are no assignment edit/delete routes:
identity is retained; reassignment is deactivate old → create new. Inactive duplicates must be reactivated,
rather than recreated. All listings/selectors paginate at 20 records; assignment lists eager-load parent identity.

Account identity linking requires rbac.view and rbac.manage plus UserPolicy school ownership:

- GET /administration/users/{managed_user}/employee: paginated available active employee picker
- PUT /administration/users/{managed_user}/employee: link, change link, or unlink with employee_id NULL

The existing scoped managed_user binding protects these routes. Inactive/already-linked/foreign employees cannot
be newly selected. The current link remains visible and can be removed, including when its employee is inactive.

## Assignment rules and lifecycle

Creation/activation requires DRAFT or ACTIVE year, eligible teacher profile and employee, active class,
active section if selected, and an active subject allocation/catalog subject for SUBJECT_TEACHER.
Selected section must belong to the selected class. Subject must be allocated to that exact year/class.
Management does not require broad permissions to edit the underlying teacher/year/class/subject modules.

CLASS_TEACHER has no subject. SUBJECT_TEACHER selects one allocated subject. A NULL section explicitly means
whole-class coverage of every active section, including active sections added later.
Only one active class teacher may cover a scope: whole-class and section-specific class-teacher coverage
cannot overlap. Separate sections may have different class teachers. Subject co-teaching by different teachers
is allowed. Identical teacher/year/class/section/subject/type records cannot be duplicated, even when inactive.

School locking serializes competing management changes; the service re-authorizes a fresh actor and rechecks
all parents/conflicts inside the transaction. Form Requests prohibit school/status/derived curriculum injection.
Closed/archived years are read-only. Draft years with retained assignments cannot be removed.

Assignments retain their raw status when a parent becomes inactive or a year closes. Effective access checks
require all parents active; historical assignment scopes are not rewritten. Reactivating parents can restore
an otherwise ACTIVE assignment's effectiveness. Explicit INACTIVE assignments remain inactive.

## Account identity and assignment-aware access

A login's teacher identity resolves only through users.employee_id → employee.teacherProfile. Linking is
explicit, audited, same-school and one-to-one. Email similarity, employee type and role names do not infer identity.
Linking does not create users/profiles or grant roles. The separate role/permission requirements still apply.
Link changes and role revocation are observed by fresh database reads rather than cached identity.

TeacherAssignmentAccess provides these context checks:

- canEnterMarks: marks.enter plus an effective SUBJECT_TEACHER assignment.
- canUpdateSubjectMarks: marks.update plus an effective SUBJECT_TEACHER assignment.
- canVerifyClass: marks.verify plus an effective CLASS_TEACHER assignment.
- canCorrectClassMarks: marks.update plus an effective CLASS_TEACHER assignment and active subject allocation.

All checks validate the requested active section belongs to the requested class. Subject-specific checks
validate the requested subject is allocated to that active academic year/class. Whole-class scope covers
only valid active sections. Disabled users/employees/profiles, inactive parents/allocations/assignments,
closed/draft years, unrelated subjects and unlinked users are denied. No implicit administrator role-name bypass exists.
The helper requires the normal established CurrentSchool context and must be combined with marks-record
workflow/locking policies in the marks-entry phase. There are no live marks routes to integrate yet.
Elevated marks overrides, submission/verification/locking, corrections/revisions and result policies remain in their phases.

## Audit behavior

TEACHER_ASSIGNMENT_CREATED/ACTIVATED/DEACTIVATED retain before/after scope/status, actor, school and timestamp.
USER_EMPLOYEE_LINKED/UNLINKED retain old/new employee identity links. Changes and audit records share a
transaction; audit failure rolls back assignments or links. No-op lifecycle/link actions do not duplicate events.
General user edits cannot change employee_id. Trusted operator provisioning still has its explicit recovery role.

## Manual testing

1. Create an active employee and teacher profile, a draft/active academic year, active class and sections.
   Allocate an active subject to that year/class through Class Subjects.
2. Open Academics → Teacher Assignments → Add assignment. Choose Subject teacher, year, teacher, class,
   a section or Whole class, then subject. Review the scope and create it.
3. Search/filter the assignment list and inspect the detail/status controls.
4. Deactivate and reactivate the record. An identical duplicate remains blocked even while inactive.
5. Create Class teacher coverage. Try another class teacher for the same section or overlapping whole-class
   coverage; verify the conflict message. Deactivate the previous record before replacing its scope.
6. Try an inactive teacher/class/section/allocation or mismatched section/subject; verify rejection.
7. Close the academic year; assignments remain readable but status changes are blocked.
8. Open Administration → Users → the intended teacher login → Link employee account. Select the employee
   behind the teacher profile. Assign the appropriate marks permissions separately through roles.
9. Verify another account cannot link that same employee and a read-only RBAC account cannot change links.
10. Inspect Audit logs for assignment and employee-link actions.
11. Run php artisan test --filter TeacherAssignmentTest for the assignment-access scenarios. Marks entry itself
    is a later phase; these checks establish its reusable authorization foundation.

## Verification and pending work

168 tests passed with 1131 assertions on school_erp_testing. New coverage includes the selection wizard,
creation/status/audits, retained NULL-scope identity, class-teacher overlap and subject co-teaching,
assignment shape, mixed-school/class/curriculum IDs, all inactive parent states, draft planning/closed years,
read-only permissions and independent management, account-link ownership/uniqueness/permissions,
exact-subject and whole-class marks scope, correction versus entry responsibilities, permission revocation,
effective-state changes, database constraints, pagination and transactional audit rollback.
Prior module regression tests remain passing. Asset build, Pint, Blade compilation and changed-code
whitespace checks passed. Browser appearance has not been visually verified.
No existing account was automatically linked and no teaching permissions or assignments were automatically granted.
The local MySQL server version remains unchanged.

## Next recommended phase

Students (plan Phase 10): school-scoped student master records and admission-number uniqueness,
validation, photos, activation/deactivation, authorization and auditing. Academic placement remains in the
later enrollment module rather than on the student master record.
