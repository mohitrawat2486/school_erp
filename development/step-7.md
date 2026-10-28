# Step 7 — Teacher Profiles (plan Phase 5)

## Existing functionality and completed work

Employee records, private employee photos, school context, teacher.view/teacher.manage permissions,
policies and audit logging already existed. Teacher profiles did not exist.

Added Teachers under the employee navigation, a searchable/paginated teacher list, an employee picker,
profile creation/detail/edit screens and activation/deactivation. Teacher identity is read from the employee
record. Qualifications, specialization, experience and profile status are stored separately.
An employee detail page links to its profile or offers profile creation when authorized.
No packages were installed and no duplicate person records were introduced.

## Files created

- database/migrations/2026_10_27_140000_create_teacher_profiles_table.php
- app/Enums/TeacherProfileStatus.php
- app/Models/TeacherProfile.php
- app/Policies/TeacherProfilePolicy.php
- app/Services/TeacherProfileService.php
- app/Http/Requests/SaveTeacherProfileRequest.php
- app/Http/Requests/TeacherProfileIndexRequest.php
- app/Http/Requests/SelectTeacherEmployeeRequest.php
- app/Http/Controllers/TeacherProfileController.php
- database/factories/TeacherProfileFactory.php
- resources/views/teachers/index.blade.php
- resources/views/teachers/select-employee.blade.php
- resources/views/teachers/form.blade.php
- resources/views/teachers/show.blade.php
- tests/Feature/TeacherProfileTest.php
- development/step-7.md

## Files modified

- app/Models/Employee.php: one-to-one teacherProfile relationship
- app/Services/EmployeeService.php: transactional profile deactivation when the employee is deactivated
- resources/views/employees/show.blade.php: profile link/creation and employee status-action explanation
- resources/views/components/app-layout.blade.php: Teachers navigation
- routes/web.php: teacher resource and status-action routes
- README.md: progress, manual access and verification totals

## Database changes

Created teacher_profiles with id, school_id, employee_id, qualification, specialization, experience,
status and timestamps. Employee identity/contact details and photos remain on employees.

- employee_id is unique: one retained profile per employee.
- Composite school_id/employee_id foreign key references employees school/id, preventing cross-school links.
- School and employee deletion are restricted while referenced.
- Composite unique school/id supports future assignment relationships.
- Index school/status supports filtered listing.
- Experience is nullable DECIMAL(4,2), representing years without floating-point calculations.
- CHECK constraints enforce ACTIVE/INACTIVE and nonnegative experience.

The additive migration was applied to school_erp. No profiles, user accounts or role grants were created automatically.

## Routes

All routes require an active authenticated school account and teacher.view.

- GET /teachers: search by employee name/number or specialization; optional profile-status filter
- GET /teachers/create: searchable/paginated eligible employee picker
- GET /teachers/create?employee_id={id}: creation form for the selected eligible employee
- POST /teachers: create a profile
- GET /teachers/{teacher_profile}: profile detail
- GET /teachers/{teacher_profile}/edit and PUT/PATCH /teachers/{teacher_profile}: profile editing
- POST /teachers/{teacher_profile}/activate: activate
- POST /teachers/{teacher_profile}/deactivate: deactivate

There is no profile deletion route. Both listing and picker paginate at 20 records and preserve search filters.
The teacher listing eagerly loads employees to avoid N+1 queries.

## Authorization and business rules

TeacherProfilePolicy requires teacher.view to read and additionally teacher.manage to create/edit/change status.
School-scoped model binding returns 404 for foreign profiles. Selected employee queries are also school-scoped.
Submitted foreign/inactive employee IDs fail validation. The service independently authorizes the fresh actor,
locks the school and linked records, and rechecks employee activity and uniqueness.

Only active employees without profiles appear in the picker. Creating a profile requires an active employee.
The linked employee is immutable after creation; qualification edits cannot move the profile to another employee.
Existing inactive profiles remain editable for historical corrections.

An active employee may explicitly receive a teacher profile regardless of their employee type: for example,
a Principal can also teach. Creating a profile does not rename or reclassify the employee, create a login,
or grant user-role permissions. Not every employee is made a teacher automatically.

Teacher management grants the minimum employee identity needed for profile selection/display, without requiring
broad employee editing access. Full employee details/photo links still require the existing EmployeePolicy.
Teacher-only readers do not receive employee home address, birth date, private contact details or photo access.

Qualifications and specialization are optional (up to 1000/255 characters respectively). Experience is optional,
0–99.99 years with up to two decimal places. Unknown experience remains NULL; zero displays as 0.00.
Status, school_id and user_id cannot be posted through the profile form.

## Lifecycle and auditing

New profiles start ACTIVE. Profile deactivation preserves qualifications and leaves the employee active.
Reactivation requires an active employee.

Deactivating an employee also deactivates their active teacher profile in the same transaction and records
reason employee_inactive. This cascade is authorized by employee.update; it does not require the employee
administrator to have separate teacher-management permissions. Reactivating an employee leaves the profile
inactive until explicitly reactivated by a teacher manager.

TeacherProfile::eligible() and isEligible() require both an ACTIVE profile and ACTIVE employee, providing the
eligibility rule for the later teacher-assignment phase. Assignment creation is not implemented here.

TEACHER_PROFILE_CREATED, TEACHER_PROFILE_UPDATED, TEACHER_PROFILE_ACTIVATED and TEACHER_PROFILE_DEACTIVATED
capture before/after values, actor, school and timestamps. No-op changes do not duplicate audits.
Audit failure rolls back profile changes. During employee deactivation, both employee/profile states and
both audit records roll back together if any audit fails.

## Manual testing

1. Sign in as a school administrator. Create an active employee if none exists.
2. Open Teachers → Add teacher profile. Search by employee name/number and select an eligible employee.
3. Enter qualifications, specialization and experience such as 3.50. Save and verify the profile details.
4. Edit the profile and verify employee identity stays linked. Try negative experience, more than two decimal
   places or values above 99.99; verify validation errors.
5. Return to the picker: the employee with a profile should no longer appear. Inactive employees are excluded.
6. Search/filter the teacher list and verify pagination retains filters.
7. Deactivate and reactivate the profile; verify qualifications remain and the employee record stays active.
8. Deactivate the linked employee. Verify the profile also becomes INACTIVE and cannot be activated yet.
9. Reactivate the employee; verify the profile remains inactive. Explicitly reactivate the profile.
10. Inspect Audit logs for profile edits and employee/profile status changes.
11. Grant teacher.view only to a test role/account. Verify it can view profiles but has no create/edit/status
    controls and cannot view full employee data without employee.view.
12. Grant teacher.view + teacher.manage to a test role/account. Verify it can manage profiles even without
    employee.update, but cannot edit the underlying employee's identity or contact information.

## Verification

96 tests passed with 571 assertions against school_erp_testing.
New coverage includes creation/editing, preserved employee identity/type, no automatic user/role creation,
unique profiles, immutable employee linkage, experience validation/null/zero/decimals, picker exclusions,
foreign-school routes/services, view-only permissions, independent teacher-management access,
activation/deactivation cascade, explicit reactivation, eligibility rules, pagination/search,
database uniqueness/composite ownership constraints and audit rollback.
Prior employee, school, RBAC, authentication and academic-year tests remain passing.
Production asset build, Pint, Blade compilation and changed-code whitespace checks passed.
Browser appearance has not been visually verified.

## Assumptions and pending work

Teaching eligibility is represented by an explicitly created profile rather than an automatic employee-type conversion.
Profiles are deactivated rather than deleted. User-to-employee linkage, assignment permissions,
classes/sections/subjects and marks workflows remain in their planned modules.
Application services enforce employee/profile status propagation; raw SQL remains a trusted infrastructure path.
The local MySQL server version remains unchanged.

## Next recommended phase

Classes (plan Phase 6): school-scoped class records, code/name/sort order/status, authorized management,
audit logging and tests. Sections follow as a separate phase.
