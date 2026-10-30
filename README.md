# School ERP

Laravel 13 modular monolith using PHP 8.3+, MySQL, Blade, Tailwind CSS 4 and Vite.
The original customer prototype is preserved in `design/`; `plan.md` remains the domain specification.

## Local setup

1. Run `composer install` and `npm ci`.
2. Copy `.env.example` to `.env`, configure database credentials, then run `php artisan key:generate`.
3. Create an empty MySQL database named `school_erp` and run `php artisan migrate`.
4. Run `php artisan app:create-user` to create your first account interactively. Passwords require at least 12 characters. No demo account is seeded.
5. Assign that account to an explicitly named school: `php artisan app:provision-school SCHOOL "Your School Name" your-email@example.com`. Replace these examples with your actual school code, name, and the account email. This trusted console command grants school administrator permissions and records the action.
6. Create later accounts through Administration → Users, assigning roles explicitly. The operator command `php artisan app:create-user --school=SCHOOL` also remains available and creates accounts without role permissions.
7. Run `npm run build`, then `php artisan serve`. Visit http://127.0.0.1:8000/login.
8. During frontend development, run `npm run dev` in a separate terminal.

The local `.env` is configured with the supplied credentials and is ignored by Git. Never commit it.
Configure your web server document root to `public/`, not the repository root.
Timezone: `Asia/Kolkata`. Session, cache and queue storage use the database.

## Step 1 delivered

- Laravel 13 foundation and locked Composer/npm dependencies.
- Session authentication with Form Request validation, session regeneration, login throttling, remember-me and POST logout.
- Responsive shared Blade layouts using the prototype maroon palette and crest.
- Protected dashboard without invented business statistics.
- Interactive account creation command; public registration is not enabled.
- Default Laravel users/password-reset/session, cache and queue tables.

Routes: `/` redirects to `/dashboard`; GET/POST `/login`; GET `/dashboard`; POST `/logout`.
All dashboard and logout routes require authentication. School context and permission checks are implemented in Step 2. Teacher-assignment business policies will be added with that module.
School Details, Academic Years, RBAC administration, Employees, Teacher Profiles, Classes, Sections, Subjects, Class Subjects and Teacher Assignments are implemented; the remaining ERP modules are pending.

## Verification

Tests use **only** a dedicated disposable database, `school_erp_testing`. Never point tests at application data.
Create that database and copy `.env.example` to `.env.testing`, set `APP_ENV=testing`,
`DB_DATABASE=school_erp_testing`, a unique application key, and local test credentials.
Then run:

```sh
php artisan test
vendor/bin/pint --test
npm run build
```

Manual check: create an account, open `/dashboard` in a private window and confirm redirect to login;
try an incorrect password, then valid credentials; verify the dashboard and responsive navigation;
sign out and confirm `/dashboard` requires authentication again. Six failed attempts within a minute should be throttled.

## Database version status

The supplied local server reports MySQL 8.0.46. Step 1 has been migrated against that server.
The requested upgrade to the latest stable MySQL is pending a separate server-maintenance operation;
installing application dependencies does not upgrade an existing database service.
Do not replace the shared server or its data directory without checking other databases and taking backups.

## Next step

All 26 numbered phases are implemented. Next: integrated acceptance and the plan’s cross-cutting indexing, security and deployment review. The previously noted MySQL server upgrade remains separate maintenance.

## Verified locally

- Laravel framework 13.38.0, PHP 8.3.6, MySQL server 8.0.46.
- Latest full regression: 542 tests pass (3346 assertions), using school_erp_testing. Class creation/editing also passes 60 responsive browser checks.
- Production Vite build, Pint, Blade compilation and migration status checks pass.
- npm audit reports no vulnerabilities at installation.
- Shared navigation and six representative screens were browser-checked at 320–1440px; print/PDF output remains unverified.

## Step 2: school access foundation

See [development/step-2.md](development/step-2.md) for implementation details and manual verification.
Existing accounts remain unassigned after migration and cannot access the dashboard until explicitly provisioned.
Logout remains accessible to those accounts. There is no automatic assignment to the first school.

- School-specific roles, a global permission catalog, school policies, and permission gates.
- `CurrentSchool` middleware/context and scoped role/audit models reject missing or mismatched school context.
- Composite foreign keys prevent assigning a role or audit actor from another school.
- Transactional `AccessManager` service records role/permission changes with before/after values.
- Read-only, paginated `/administration/audit-logs` requires `audit.view`.
- Provisioning creates the seven planned role names, but grants default permissions only to SCHOOL_ADMIN. Other roles intentionally begin empty until their module policies and permission matrix are configured.
- SUPER_ADMIN has no implicit cross-school bypass.
- Audit records reject instance updates/deletions, redact secret keys recursively, and capture actor/time/request metadata.
- Queue jobs must establish their explicit school context and clear it in a finally block.
- Raw SQL, mass updates, and `withoutGlobalScopes()` bypass model protections: reserve them for reviewed infrastructure paths. Audit logs are application-protected, not tamper-proof against database administrators.
- `app:create-user` and `app:provision-school` are trusted operator commands; do not expose them through HTTP.

Step 5 now supplies role editing screens, calling AccessManager rather than writing pivot tables directly.

## Step 3: School Details

Open **Settings → School Details** (`/settings/school`) as a provisioned school administrator.
View requires `school.view`; saving also requires `school.update`. No school ID is accepted from the browser.
School identity, contact details, address, timezone and private logo uploads are supported.
Updates and their before/after audit entries are transactional. Uploaded logos appear in the school sidebar.
PNG/JPEG/WebP logos are limited to 2 MB and 2000×2000 pixels; `storage:link` is not required.
See [development/step-3.md](development/step-3.md) for files, schema changes, routes, manual checks and limitations.

## Step 4: Academic Years

Open **Academics → Academic Years** (`/academics/academic-years`) as a school administrator.
Create a draft with a session name and dates, then activate it to select it as the current year.
Only one current year per school is permitted; selecting another preserves previous records.
Drafts can be edited or removed. Active years can close, and closed years can archive.
All changes are audited. The header displays the actual current year.
See [development/step-4.md](development/step-4.md) for schema, files, routes, permissions, lifecycle decisions and manual checks.

## Step 5: RBAC administration

Use **Administration → Users / Roles / Permissions** to manage school accounts and access.
`rbac.view` grants read-only administration; changes also require `rbac.manage`.
Managers can create/edit accounts, assign multiple roles, change passwords, enable/disable accounts,
create/rename roles, and configure their permissions. Permission names are read-only.
Changes cannot disable your own account or remove the school's last active RBAC manager.
All changes are transactional and audited; there are no new migrations in this phase.
See [development/step-5.md](development/step-5.md) for files, routes, manual verification and limitations.

## Step 6: Employee Management

Open **Employees → Employee List** (`/employees`) to create, view, edit, search and filter staff records.
Records have school-unique employee numbers, employee types, personal/contact/employment details, private photos
and explicit activation/deactivation. Viewing requires `employee.view`; creating also needs `employee.create`;
editing and changing status also need `employee.update`. All mutations are audited.
Employee creation does not create a login or teacher profile. Inactive records remain available for history.
The employees-table migration is applied. Photos use private storage; no `storage:link` is needed.
See [development/step-6.md](development/step-6.md) for files, schema, routes, manual checks and assumptions.

## Step 7: Teacher Profiles

Open **Teachers** (`/teachers`) to create a profile for an existing active employee.
Teacher identity stays on the employee record; profiles hold qualifications, specialization and decimal experience in years.
Viewing requires `teacher.view`; creating/editing/activation also requires `teacher.manage`.
Each employee can have one profile. Deactivating an employee also deactivates their teacher profile transactionally;
reactivating the employee requires explicit teacher-profile reactivation afterward.
Creating profiles does not create logins or grant role permissions. The teacher_profiles migration is applied.
See [development/step-7.md](development/step-7.md) for files, schema, routes, permissions, manual checks and assumptions.

## Step 8: Classes

Open **Academics → Classes** (`/academics/classes`) to create, view, edit, search and order your school's classes.
Names/codes are unique per school, and numeric display order controls the listing. Inactive records are retained.
Viewing requires `class.view`; creating/editing/activation also requires `class.manage`.
The classes-table migration is applied. Sections reference persistent class records; academic-year allocations remain separate.
All changes are transactionally audited. See [development/step-8.md](development/step-8.md) for files,
schema, routes, manual checks and assumptions.

## Step 9: Sections

Open **Academics → Sections** (`/academics/sections`) to create sections within active classes.
Section names are unique per class; optional capacity, search, class/status filters and activation controls are supported.
Viewing requires `section.view`; creating/editing/activation also requires `section.manage`.
The linked class stays fixed after creation. Deactivating a class deactivates its active sections transactionally;
reactivating the class leaves section reactivation explicit. The sections migration is applied.
See [development/step-9.md](development/step-9.md) for files, schema, routes, manual checks and assumptions.

## Step 10: Subjects and Class Subjects

Use **Academics → Subjects** (`/academics/subjects`) for the Core/Optional subject catalog,
and **Academics → Class Subjects** (`/academics/class-subjects`) to allocate subjects by year and class.
Allocations have optional decimal theory/practical maximums and passing marks, validated exactly on the server.
Viewing requires `subject.view`; changes also require `subject.manage`. Closed/archived years are read-only.
The subjects/class_subjects migration is applied; no default subject records were seeded.
See [development/step-10.md](development/step-10.md) for files, schema, rules, routes and manual checks.

## Step 11: Teacher Assignments

Open **Academics → Teacher Assignments** (`/academics/teacher-assignments`) to assign class or subject teachers
by year, class and optional section. Viewing requires `teacher_assignment.view`; changes also need `teacher_assignment.manage`.
Assignments validate eligible parents and exact curriculum context, prevent overlapping class-teacher coverage,
and retain scope/history through activation controls. Whole-class assignments cover all active sections.
Link each teacher login explicitly through **Administration → Users → Link employee account**, then assign role permissions.
`TeacherAssignmentAccess` combines that identity, permissions and effective assignment scope for the later marks module.
The assignments/account-link migration is applied; no accounts or permissions were automatically linked/granted.
See [development/step-11.md](development/step-11.md) for files, schema, access rules, routes and manual checks.

## Responsive navigation

The sidebar now has icons and slides out to the left when collapsed on desktop, remembering the browser preference.
On phones/tablets it opens as a modal drawer with a close button, backdrop, Escape support and keyboard focus handling.
The shared header, forms and scrolling tables adapt to narrow screens. Existing permissions continue to control navigation.
See [development/ui-navigation.md](development/ui-navigation.md) for verification and manual checks, plus
[desktop](development/previews/sidebar-desktop.png), [mobile drawer](development/previews/sidebar-mobile-open.png)
and [mobile content](development/previews/sidebar-mobile-content.png) previews.

## Full-width lists

Directories, selection tables and the permissions catalog now fill the available workspace width.
Shared table panels provide consistent filters, status badges, actions, record counts and pagination, with responsive scrolling on phones.
See [UI verification and previews](development/ui-lists.md).

## Step 12: Students

Open **Students → Student List** (`/students`) or **Admission** (`/students/create`) to manage student master records.
The full-width directory supports name/admission-number search, status filters and pagination. Create, edit, private photos and activation/deactivation are permission-controlled and audited. Admission numbers are unique per school; records are retained.
Academic placement remains in the later enrollment phase.
See [development/step-12.md](development/step-12.md) for files, database changes, routes, assumptions, tests and manual checks.

## Step 13: Guardians

Open **Students → Guardians** (`/guardians`) to manage shared family contacts. Open a student's details → **Manage guardian contacts** to link existing guardians, set Father/Mother/Guardian relationships, designate primary/emergency contacts or unlink while retaining history.
Reading requires `student.view`; managing contacts additionally requires `student.update`. At most one active primary contact is permitted per student. Replace or unlink primary relationships before deactivating a guardian. Editing a shared guardian updates the contact for all linked students.
See [development/step-13.md](development/step-13.md) for files, database constraints, routes, assumptions, tests and manual checks.

## Step 14: Student Enrollment

Open **Students → Enrollment** (`/enrollments`) or a student's details → **Enroll student**. Search/select the active student, academic year, class and section, then enter roll number and joined date. Enrollment history appears on the student's details page.
Reading requires `student.view`; changes additionally require `student.update` and an active academic year. One enrollment per student/year and unique roll numbers per year/class/section are enforced. Section capacity and joined dates are checked. Context is fixed after creation; roll/date corrections and status changes are audited. History is retained.
See [development/step-14.md](development/step-14.md) for files, constraints, routes, assumptions and manual testing.

## Step 15: Student Promotion

Open **Students → Promotion** (`/promotions`) to promote/repeat selected students into a later active year or record withdrawal/transfer from an active source year. Enrollment details provide individual shortcuts. Choose source/target sections, enter new roll numbers, review the selected students and confirm.
Promote/Repeat creates new enrollments without overwriting the source placement. Withdrawal/Transfer retains terminal source history. Batches are atomic, audited and protected against duplicate submissions. Reading requires `student.view`; processing additionally requires `student.update`.
See [development/step-15.md](development/step-15.md) for files, database changes, routes, assumptions and manual checks.

## Step 16: Examination Module

Open **Examinations → Exam Terms** (`/examinations/terms`) to define year-specific terms, then **Exam Setup** (`/examinations/exams`) to create class exams with dates within the year. Draft details are editable. Configure at least one valid active exam subject before opening; Open freezes setup, Return to draft permits revisions, and Close retains a final record.
Reading requires `exam.view`, creation additionally requires `exam.create`, and setup changes require `exam.update`. Terms/exams remain school-scoped and audited. Marks entry starts through the Marks Entry workflow; later verification/result states remain reserved. Close all exams before closing their academic year.
See [development/step-16.md](development/step-16.md) for files, constraints, routes, assumptions and manual checks.

## Step 17: Exam Subjects

Open **Examinations → Exam Setup**, view a draft exam and choose **Add exam subject**. Select curriculum from the exam's year/class, confirm or override copied theory/practical maximums and enter explicit passing marks. Configuration is also searchable under **Examinations → Exam Subjects** (`/examinations/exam-subjects`).
Combined maximum must be positive and passing marks cannot exceed it; two decimal places are supported. Saved exam marks stay independent of later curriculum changes. Adding/editing/status changes require `exam.update` and a draft exam in a writable year; reading requires `exam.view`. Opening checks active subject readiness and freezes configuration.
See [development/step-17.md](development/step-17.md) for files, constraints, routes, assumptions and manual checks.


## Step 18: Marks Entry

Open **Examinations → Marks Entry** (`/examinations/marks`). Choose academic year, exam/class, subject and section, or search the subject directory. Enter marks in a full-width paginated roster; save partial drafts, mark absence or submit completed editable rows on the current page. Totals are calculated on the server. The first successful save moves the exam to MARKS_ENTRY; submitted rows become read-only.

Reading requires `marks.view`; saving requires `marks.enter`, existing drafts additionally require `marks.update`, and submission also requires `marks.submit`. Subject teachers see only effective assignments for the exact year/class/subject/section. `marks.verify` or `marks.lock` grants elevated school-wide context access while retaining action permission checks. Bulk saves are atomic and audited; row versions prevent stale edits.
See [development/step-18.md](development/step-18.md) for schema, routes, permissions, assumptions and testing instructions.


## Step 19: Marks Verification

Open **Examinations → Marks Verification** (`/examinations/marks-review`) to review submitted entries, verify them, then lock them. The searchable queue is paginated and includes workflow actors/timestamps. Unlock requires its own permission and a nonblank reason, returns the row to draft, and clears approvals so the correction must be submitted and reviewed again.

Reading requires `marks.view` plus a review permission. Actions separately require `marks.verify`, `marks.lock` or `marks.unlock`. These permissions grant school-wide review access; ordinary teacher entry retains assignment restrictions. Actions recheck fresh state and row versions, and every transition is transactional with its audit. Later exam states and closed years remain read-only.
See [development/step-19.md](development/step-19.md) for files, routes, lifecycle decisions, permissions and testing instructions. No migration is needed because the marks schema already contains workflow fields.


## Step 20: Grading

Open **Examinations → Grading Schemes** (`/examinations/grading-schemes`). Create an inactive scheme, configure percentage ranges with grade labels and optional points/remarks, then activate. Active schemes provide a server-side percentage lookup and remain read-only until deactivated. Schemes and rules are paginated and school-scoped; no grading thresholds are seeded or hard-coded.

Ranges use inclusive two-decimal endpoints. Activation requires continuous 0.00–100.00 coverage with no gaps or overlaps. Reading requires `exam.view`; administration additionally requires `exam.update`. All effective changes are audited and protected by scheme versions. The grading migration is applied; existing marks are unchanged.
See [development/step-20.md](development/step-20.md) for files, schema, range semantics, routes, permissions and manual testing instructions.


## Step 21: Result Calculation Service

`ResultCalculationService` provides server-side subject, weighted total, percentage, configured grade, pass/fail and competition-rank calculations. Percentages use integer arithmetic and half-up rounding to two decimals; pass/fail compares original marks. Absence contributes zero and fails the subject. Only passing students receive ranks, with ties such as 1, 1, 3.

`calculateExam($actor, $exam, $scheme, $section)` requires `result.view` and `result.generate`, an active school/year/exam/grading context and complete locked marks for the selected class or section. It returns a read-only draft with source marks versions and copied grade values. Roster queries use chunks while ranking spans the entire cohort. Missing/unlocked marks fail the whole calculation.

This phase adds the calculation service and tests. Result generation/approval screens and persisted publication snapshots follow in the next phases. No marks, configuration, permissions or schema are modified by calculation.
See [development/step-21.md](development/step-21.md) for APIs, arithmetic/ranking policies, output fields, limits and verification instructions.


## Step 22: Result Approval

Open **Results → Result Approval** (`/results`). Generate a class-wide revision from complete locked marks and an active grading scheme, review saved student/subject results, approve and publish. Reading requires `result.view`; generation, approval and publication independently require `result.generate`, `result.approve` and `result.publish`.

Generation freezes marks during review. Before approval/publication, source and payload integrity checks reject stale marks, roster or grading changes. Return for correction requires an approver and a reason, reopens marks entry and retains the old revision. Published payloads remain saved and read-only; later live grading/name changes do not rewrite them. Published exams can close through Exam Setup without altering results, allowing academic-year closure.
See [development/step-22.md](development/step-22.md) for files, schema, lifecycle, integrity checks, routes, permissions and testing. The result batch migration is applied; normalized publication snapshots follow in plan Phase 21.


## Step 23: Result Snapshot

Publication now creates normalized immutable `student_results` and `student_result_subjects` from the saved approved payload in the same transaction. Snapshots retain original names, marks, grades, points, publication time and revision; published pages read those copies instead of live configuration. Open a published batch under **Results → Result Approval**, then student Details. Existing student-result URLs remain compatible.

Application guards and MySQL triggers block updates, deletes and later additions after archival is sealed. Reading requires `result.view`; archival requires `result.publish` as well. Historical backfill is available through `php artisan results:archive-published SCHOOL authorized-operator@example.com [--batch=ID]`, without recalculating results. The local database had no pending legacy publications after migration.
See [development/step-23.md](development/step-23.md) for files, schema, database protections, backfill behavior, routes and verification.


## Step 24: Class-wise Results

Open **Results → Class-wise Results** (`/class-results`). Choose historical academic year, exam, class and section, then browse/search published student totals, percentages, grades, statuses and original class ranks. All filters and values come from immutable snapshots; closed years and renamed live records remain readable.

View individual snapshots, print one/selected/filtered/all-class summaries, or download a real Excel `.xlsx` summary across all matching pages. Printing/export additionally require `result.print`; reading requires `result.view`. Selection is validated against the cohort, text cells remain safe spreadsheet text, and outputs are capped at 5000 students without truncation. No schema or role grants changed.
See [development/step-24.md](development/step-24.md) for files, routes, print/export scope, limits and testing. Individual report-card and PDF layouts follow in the next phases.


## Step 25: Report Card

Open a published student result → **Print report card**, or use individual **Print** in Class-wise Results. The responsive A4 portrait report includes captured school branding/address, student identity, subject component marks, totals, percentage, grade/status, teacher remarks and signature lines. Dedicated print CSS hides controls and supports long reports across pages.

Printing requires `result.view` and `result.print`. New generations/publications capture admission number, DOB and school branding; historical reports never substitute live identity or logo files. Older publications without those fields explicitly mark them as not recorded. The nullable report-identity migration is applied without rewriting existing snapshots. Browser printing is available; server PDF output follows in Phase 24.
See [development/step-25.md](development/step-25.md) for files, captured-context rules, legacy behavior, permissions and print verification.


## Step 26: Class-wise PDF

Individual report cards now have **Download PDF**. Class-wise Results provides **PDF Selected**, **PDF filtered results** and **PDF Entire Class**, prepared in the background and downloaded from **My PDF Downloads**. Files use published snapshots, preserve student order and include every continuation page. Downloads are private, creator-scoped and expire after 24 hours.

Run `composer run pdf:work` for batch processing, or restart `composer run dev` to include the local PDF Worker. A dedicated database queue, chunked Dompdf rendering and FPDI merging keep class batches out of HTTP requests. The request-table migration is applied; result access/print permissions are reused. The current local PDF worker was started during implementation. Schedule Laravel's scheduler for daily expired-file cleanup.
See [development/step-26.md](development/step-26.md) for files, queue setup, limits, privacy/expiry behavior and tests. Review the [sample individual PDF](development/previews/report-card-server.pdf) or [sample combined class PDF](development/previews/report-card-class.pdf).


## Step 27: Excel Export

Open **Results → Class-wise Results → Export Excel · Subject marks (.xlsx)** to download all filtered students across pages. The workbook includes admission numbers, individual subject totals and the required overall result fields, plus detailed component marks and export notes. Published snapshots supply all data; leading zeros, absent marks and historical identity gaps remain explicit.

The existing XLSX writer now streams styled worksheets with frozen headings and filters. No additional package or migration is required. See [development/step-27.md](development/step-27.md) for limits, access and validation, or download the [sample workbook](development/previews/class-results-detailed.xlsx).


## Step 28: Audit Logging

Open **Administration → Audit logs** to filter school history by dates, actor, module, action or record. **View changes** displays before/after values and recorded request context. New marks events capture student/subject names; actor names remain as recorded at action time. Older entries explicitly show missing names.

Account enable/disable transitions now have explicit events. The applied migration adds nullable audit context, filter indexes and MySQL triggers preventing audit updates/deletes. Existing required marks, unlock, result, RBAC and academic events remain transactional. Reading requires `audit.view`; no new grants or dependencies are needed.

See [development/step-28.md](development/step-28.md) for coverage, files, verification and screenshot previews. This completes the final numbered phase in `plan.md`.

## Class 1 sample records

The local school includes **Class 1A and 1B**, ten sample students per section, ten teachers, ten subjects, linked guardians and published sample results. Open **Results → Class-wise Results** and choose the published Class 1 assessment, then **A/B** (historical published labels retain their original names). See [sample-data notes](development/sample-class-one.md) for the repeatable command, records and verification.

## Create a class with sections, subjects and teachers

Open **Academics → Classes → Add class**. Enter a name; the code and display order are generated automatically. Select sections (A–D or custom names), optionally set student limits, then choose subjects for the current academic year. You can copy subject choices from another class or add sections/subjects later. Optionally choose class and subject teachers through a searchable pop-up. One save creates the complete setup.

**Edit class** includes the same sections, subjects and teacher inputs, prefilled with saved values. Teacher replacements preserve assignment history. In-use items stay selected to protect students, teachers and exams; unchecked unused sections are deleted, while unused subject mappings are retained as inactive. Class code remains automatic and read-only.

Existing classes and published records are retained. See [class setup notes](development/class-setup.md) and [teacher setup notes](development/class-teachers.md) for behavior, permissions, tests and previews.

## Searchable lists

Teacher Assignments and the other record lists now use DataTables with search across all matching records, page-size controls and pagination without reloading the page. Existing filters and access permissions apply. Published-result selection and export filters follow the current table view. Editing forms keep their current save workflow.

See [list table notes](development/list-tables.md) for coverage and implementation details.

## Teachers workspace

Open **Employees → Teachers** to manage profiles and assignments from one workspace. Each teacher has **View profile** and **Assignments** actions, plus an active assignment count for the current academic year. **All assignments** provides the school-wide view. Adding an assignment from a teacher preselects them; filters and back links preserve the chosen teacher and directory context. Existing assignment-only access and historical records are retained.

List actions across the app now use consistent labelled icons. See [teacher workspace notes](development/teacher-workspace.md) for behavior, permissions and previews.

## Subjects workspace

Open **Academics → Subjects** to manage the subject list and class use together. **Classes → Manage classes** provides one searchable checklist for the selected subject and academic year. Changes save together; unused removals require confirmation, used links stay protected, and restored links retain their marks settings. **By class** provides an overview and class-specific **Marks settings** actions. Existing class creation and historical records remain supported.

See [subject workspace notes](development/subject-workspace.md) for behavior, permissions and previews.
