# Step 12 — Students (plan Phase 10)

## Completed

School-scoped student admission records with full-width, paginated directory; name/admission-number search and status filter; create, view and edit; activation/deactivation; private photo uploads; permission checks and transactional audit logging. Student placement is intentionally absent: classes, sections and roll numbers belong to the later enrollment module. Guardian and enrollment records are not implemented in this phase.

## Files created

- `database/migrations/2026_10_27_190000_create_students_table.php`
- `app/Models/Student.php`, `app/Enums/StudentStatus.php`
- `app/Policies/StudentPolicy.php`, `app/Services/StudentService.php`
- `app/Http/Controllers/StudentController.php`
- `app/Http/Requests/SaveStudentRequest.php`, `StudentIndexRequest.php`
- `database/factories/StudentFactory.php`
- `resources/views/students/index.blade.php`, `form.blade.php`, `show.blade.php`
- `resources/views/components/form-field.blade.php`
- `tests/Feature/StudentTest.php`
- `development/previews/students-desktop.png`, `students-mobile.png`

## Files modified

`routes/web.php` registers student routes. `School.php` adds the students relationship. The application layout adds permission-aware Student List and Admission links with icons. The existing employee field component delegates to the shared form field component to preserve existing screens without duplicating field rendering. README records completion and next phase. Built frontend assets were regenerated.

## Database and relationships

The `students` table contains school ID, admission number, first/middle/last names, gender, birth and admission dates, blood group, private photo path, address, ACTIVE/INACTIVE status and timestamps.

- School foreign key restricts deletion; Student belongs to School, School has many Students.
- Unique `(school_id, admission_number)` preserves school-level identity; unique `(school_id, id)` prepares for future composite references.
- Indexes cover school/status/name and school/admission date.
- Database checks constrain status, gender and blood group and require birth date before admission date when supplied.
- There are no class, section or roll-number columns. Submitted placement fields are rejected.
- Migration applied to `school_erp`; no student records seeded.

## Routes and authorization

- `GET /students` — list/search/filter
- `GET /students/create`, `POST /students` — admission
- `GET /students/{student}`, `GET /students/{student}/edit`, `PUT /students/{student}` — details/edit
- `POST /students/{student}/activate`, `/deactivate` — retain records and change status
- `GET /students/{student}/photo` — authenticated, school-scoped photo response

`student.view` is required throughout. Creating additionally requires `student.create`; editing and status changes require `student.update`. Existing permissions are reused without changing role grants. `student.delete` is not used: physical deletion has no route. Route binding uses the existing school scope; foreign-school IDs return 404. The service rechecks fresh authorization and uses a school lock plus row locks, a transaction and database uniqueness constraints. Creation, changes and status transitions are audited; unchanged saves/status requests avoid duplicate events. Failed audit writes roll back database changes and remove newly uploaded files.

Photos reuse existing raster rules: PNG/JPEG/WebP, maximum 2 MB and 2000×2000 pixels. They stay on the private local disk and are delivered with authorization, private/no-store and nosniff headers. Replacement retains prior files for audit history, matching the employee module; retention cleanup remains a future storage-maintenance concern.

## Validation and assumptions

Admission number, first name and admission date are required; other demographic fields are optional. Admission numbers are entered manually. Birth date cannot be future and must precede admission. Future admission dates are allowed for advance admissions. ACTIVE/INACTIVE indicates the master record's availability; future enrollment workflows will own withdrawal, transfer and promotion history. Guardians remain a separate later module.

## Verification

- All 182 tests / 1,243 assertions passed on the separate `school_erp_testing` database.
- 14 new student tests cover guest/read-only/unauthorized access, school-scoped CRUD and audits, malformed/forbidden fields and dates, per-school and database uniqueness, status history and disabled deletion, cross-school endpoint/service rejection, search/status/pagination, private photos, upload format/size/dimensions, replacement preservation and audit rollback cleanup.
- Pint, production Vite build, Blade compilation and changed-file whitespace checks passed.
- Chromium checked list, admission, detail and edit screens at 320, 390, 768, 1440 and 1920 pixels: no document overflow, one active navigation entry, required fields and absence of academic placement inputs. Browser fixtures were rolled back in the testing database; no preview records were added to the application database.

## Manual testing

1. Sign in using an account with `student.view`, `student.create` and `student.update` (grant through existing Roles/User administration if needed).
2. Open **Students → Admission**. Enter a unique admission number, first name and admission date, optionally demographic information and a valid photo. Submit.
3. Verify the detail page; edit personal information and confirm the saved values.
4. Open **Students → Student List**. Search by admission number or name, filter status, reset and navigate pages when more than 20 records exist.
5. Open a student's details, expand Deactivate student, confirm, and verify the record remains searchable under Inactive. Activate it again.
6. Try a duplicate admission number, invalid blood group/birth date or SVG/oversized upload and verify validation feedback.
7. Verify a view-only account can read but cannot create, edit, change status or use mutation endpoints. Confirm foreign-school IDs are inaccessible.
8. Check **Administration → Audit Logs** with `audit.view` for STUDENT_CREATED, STUDENT_UPDATED and activation/deactivation events.
9. Check desktop expanded/collapsed sidebar and phone table scrolling.

## Pending / next recommended phase

Guardians (plan Phase 11): guardian master records and student–guardian relationships. The previously documented MySQL server upgrade remains separate; this phase uses the existing server and does not change its version.
