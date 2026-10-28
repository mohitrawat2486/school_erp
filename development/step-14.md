# Step 14 — Student Enrollment (plan Phase 12)

## Completed

School-scoped academic placement records with searchable, paginated student → active academic year → class → section selection. Full-width enrollment directory supports student/admission number/year/class/section search, status filtering and exact school-scoped query filters. Each record has details, roll-number/joined-date correction and activation/deactivation. Student details show paginated enrollment history. Promotion is not implemented in this phase.

## Files created

- `database/migrations/2026_10_27_210000_create_student_enrollments_table.php`
- `app/Enums/EnrollmentStatus.php`, `app/Models/StudentEnrollment.php`
- `app/Policies/StudentEnrollmentPolicy.php`
- `app/Services/EnrollmentService.php`, `EnrollmentSelection.php`, `EnrollmentDirectory.php`
- `app/Http/Controllers/StudentEnrollmentController.php`
- `app/Http/Requests/EnrollmentIndexRequest.php`, `SelectEnrollmentRequest.php`, `SaveEnrollmentRequest.php`
- `resources/views/enrollments/index.blade.php`, `select.blade.php`, `form.blade.php`, `show.blade.php`
- `resources/views/components/enrollment-table.blade.php`
- `tests/Feature/EnrollmentTest.php`

Modified: Student/AcademicYear/SchoolClass/Section/School models for enrollment relationships; student controller/details; sidebar and routes; StudentService admission-date consistency; SectionService capacity consistency; AcademicYearService deletion/date consistency; README; built frontend assets.

## Database changes and relationships

`student_enrollments`: school, student, academic year, class, required section, positive integer roll number, joined date, ACTIVE/INACTIVE status and timestamps. No placement columns are added to students.

- Composite foreign keys ensure student/year/class belong to the same school and section belongs to that school/class.
- Unique school/year/student means one retained enrollment per student per year, including inactive records. This follows the plan's stronger recommended unique index, rather than allowing multiple inactive duplicates.
- Unique school/year/class/section/roll prevents duplicate roll numbers; inactive rows reserve their roll number for historical clarity.
- Indexes support year/class/section/status directory queries and student/year history. School/id is unique for future composite references.
- Database checks enforce positive roll numbers and controlled statuses. Restrictive foreign keys preserve academic records.
- StudentEnrollment belongs to Student, AcademicYear, SchoolClass, Section and School. Each parent has many enrollments. AcademicYear relation includes soft-deleted years for historical reading.

## Rules and authorization

Reading requires `student.view`; all enrollment changes additionally require `student.update`. Existing permission grants are reused. Policies check school ownership; route binding uses the established school scope; requests reject forbidden school/status/identity changes. Services independently recheck fresh permissions after locking the school, then lock enrollment/context records inside transactions. All effective saves and status transitions are audited; audit failure rolls back changes.

Only ACTIVE academic years accept enrollment changes. DRAFT/CLOSED/ARCHIVED years are read-only. Creating/reactivating requires active student/class/section and available capacity. Capacity counts ACTIVE enrollments in that section for the selected year, not across all historical years. Null capacity is unlimited. Parent deactivation retains placement history rather than cascading changes. Inactive enrollment metadata can be corrected in an active year; reactivation rechecks eligibility and capacity.

Joined date must be within the academic year and on/after student admission. Subsequent student admission-date changes cannot move admission after an existing joined date. Section capacity cannot be reduced below active enrollment in any year. Academic year deletion/date editing cannot invalidate attached enrollment history.

Student/year/class/section are fixed after creation. Roll and joined date can be corrected with an audit trail while the year is active. New-year enrollment creates a separate record. There is no delete endpoint. Same-year placement corrections/transfers and promotion workflow are not included; the next phase must define them without overwriting historical placement.

## Important routes

- `GET /enrollments` — search/status filters; optional exact `student_id`, `academic_year_id`, `class_id`, `section_id` query filters
- `GET /enrollments/create` — searchable selection steps and final form
- `POST /enrollments` — save placement
- `GET /enrollments/{enrollment}`, `/edit`; `PUT /enrollments/{enrollment}` — details/corrections
- `POST /enrollments/{enrollment}/activate`, `/deactivate` — retained status history
- Student details → Enrollment history / Enroll student

## Assumptions / potential issues

Sections are required, matching the plan's enrollment table; create an appropriate section for classes without subdivisions. Roll numbers are positive integers (no alphanumeric values or meaningful leading zeroes). Inactive records reserve student/year and roll identity. Only active years accept actual enrollment; draft planning can be added later if needed. Admission dates and joined dates may be future dates for advance admissions within an active year. Missing required parent records must first be configured by an authorized administrator. No enrollment permissions, parent records or enrollment data are automatically seeded.

## Manual testing

1. Prepare an active academic year, active class and active section with sufficient capacity. Create an active student with an admission date no later than the proposed joined date.
2. Sign in with `student.view` and `student.update`; open **Students → Enrollment → Enroll student**. Search/select the student, year, class and section; enter roll number and a joined date within the year; confirm.
3. Verify details, then edit the roll/joined date and check the saved values and audit log. Student/year/class/section should not be editable.
4. Open the student's details and confirm Enrollment history. Create a different active year and enroll the same student there; both records should remain.
5. Try duplicate student/year and duplicate roll in the same year/class/section, zero/negative roll, wrong section/class, invalid joined date and a full section. Verify validation messages.
6. Deactivate/reactivate an enrollment. Verify its row/history remain; reactivation should fail when capacity is full or a parent is inactive.
7. Close the year; history should remain readable and mutation controls should disappear. Direct edit/update/status endpoints must reject changes.
8. Try moving admission date beyond a joined date or reducing capacity below active occupancy; verify rejection.
9. Verify view-only users can read but cannot create/edit/change status. Foreign-school records and selections must remain inaccessible.
10. Check desktop/phone table scrolling and directory pagination. Search by student name, admission number, year/class/section text, and status. Use Reset to remove all filters.

## Next recommended phase

Student Promotion (plan Phase 13): individual/bulk new-year enrollments, section/roll assignment, repeaters, withdrawal and transfer handling while retaining historical placement.

## Verification

- Migration applied to `school_erp`; no actual enrollment records seeded.
- Full regression suite: 216 tests / 1,524 assertions passed on `school_erp_testing`.
- 20 new enrollment tests cover selection/CRUD/audits, year history and duplicate identity, roll reservation and section scoping, capacity by year/reactivation, dates/rolls/forbidden fields, class-section mismatch and inactive parents, read-only years, idempotent statuses, read-only/guest access, cross-school endpoints/payloads/services, search/filters/pagination, creation/status audit rollback, immutable service context, admission/capacity consistency, and database constraints against duplicate student/year, duplicate roll, mixed class/section and foreign-school student.
- Production Vite build, Blade compilation, Pint and changed-code whitespace checks passed.
- Chromium checked directory, four selection steps, final form, details, edit and student history at 320, 390, 768, 1440 and 1920 pixels. Checks covered document overflow, active navigation, required controls, immutable edit context and server pagination. Preview records were created inside a rolled-back testing-database transaction.

Previews: [directory](previews/enrollments-desktop.png), [enrollment form](previews/enrollment-form-desktop.png), [mobile directory](previews/enrollments-mobile.png).
