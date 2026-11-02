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
2. Open **Students → Add student**. Enter a unique admission number, first name and admission date, optionally demographic information and a valid photo. Add a new guardian, choose an existing contact or select Add later. Submit.
3. Verify the detail page; edit personal information and confirm the saved values.
4. Open **Students**. Search by admission number or name, filter class/section and status, reset and navigate pages when more than 20 records exist.
5. Open a student's details, expand Deactivate student, confirm, and verify the record remains searchable under Inactive. Activate it again.
6. Try a duplicate admission number, invalid blood group/birth date or SVG/oversized upload and verify validation feedback.
7. Verify a view-only account can read but cannot create, edit, change status or use mutation endpoints. Confirm foreign-school IDs are inaccessible.
8. Check **Administration → Audit Logs** with `audit.view` for STUDENT_CREATED, STUDENT_UPDATED and activation/deactivation events.
9. Check desktop expanded/collapsed sidebar and phone table scrolling.

## Pending / next recommended phase

Guardians (plan Phase 11): guardian master records and student–guardian relationships. The previously documented MySQL server upgrade remains separate; this phase uses the existing server and does not change its version.

## Inline guardian editor in Add/Edit Student

Both forms share one guardian editor with up to three active student–guardian links. Compact cards show name, phone, relationship, primary status and emergency preference. Add a new contact or search an active existing contact by name/phone. Existing contact fields require an explicit Edit contact details action, with a notice explaining shared sibling updates. One primary contact is required when any guardians remain. Contacts are optional, so zero is valid. New contact details can copy the student's address; existing details retain their address unless explicitly edited.

Remove marks a card as pending and offers Undo. Existing links also require confirmation in the removal review. The main Create student / Save student action applies all changes together; removed relationships become inactive while guardian records and sibling links remain. A guardian-only edit works even when student fields are unchanged, and unchanged saves avoid audit churn. Existing links above the new limit are preserved, but additions are blocked until the total fits within three. The older linking endpoint enforces the same cap under its existing school lock.

`GuardianEditorRules` validates bounded nested inputs. `GuardianEditorState` snapshots student/contact/link state so stale edits cannot overwrite newer changes. `StudentGuardianForm` restores validated drafts and pending removals; stale snapshots reload the latest guardian state with an explanation. `StudentGuardianEditor` uses existing guardian/link services inside the student's transaction, removing obsolete links before adding replacements at capacity. Audit or validation failures roll back all database changes and remove a newly uploaded photo. Existing create-only admission payloads remain supported for older clients.

`students.guardian-editor-options` accepts a school-scoped student ID for edit-only staff, or requires student creation permission for a new admission. It returns at most 20 active contacts with contact details/revisions needed for explicit inline editing. It requires guardian management access and private/no-store responses. Search preserves the current selection and offers retry on failure. No roles, schema or live-school records were changed. Without JavaScript, guardian controls remain disabled so a student-only save cannot unintentionally unlink contacts.

Verification: **58 related tests / 517 assertions passed**, covering additions, replacements at capacity, primary changes, shared updates, removal confirmation/history, validation recovery, duplicate/foreign/inactive contacts, permissions, bounded search, stale snapshots, legacy over-limit links, audit rollback and no-op saves. Chromium checks passed at 320, 390, 768 and 1440 pixels, including card limits, Undo/confirmation, selection and draft retention, search recovery, restored validation errors and page overflow. Browser fixtures were rendered in a rolled-back transaction in `school_erp_testing`.

Previews: [desktop guardian editor](previews/student-guardians-edit-1440.png), [mobile guardian editor](previews/student-guardians-edit-390.png).

## Student profile presentation

Student identification: Student details in Add/Edit Student includes a mandatory 12-digit Aadhaar number, always visible outside the optional personal-details disclosure. Values are encrypted in the database and hidden from model JSON; profiles display only the last four digits, and audits redact the number. Creating and editing require a valid number under the existing student permissions; clearing or omitting it is rejected. The additive employee/student migration and verification are documented in [step-6.md](step-6.md#aadhaar-identification).

The View student page has a responsive identity header (photo/initials, admission number, status, current class/section, academic year and admission date), personal details/address card, and a simple guardian contact list. Primary/emergency preferences and inactive guardian status are visible beside contact information. Optional contact fields and previous guardian links use native expandable details, with no DataTables controls or requests. Enrollment history retains its existing server-paginated DataTable. Edit actions respect student permissions and guardian editing opens the inline student editor.

Verification: **44 related tests / 428 assertions passed**, including current-year placement and guardian current/history list rendering without tables. Chromium checks passed at 320, 390, 768 and 1440 pixels for contacts, phone/email links, inline expansion, current/no-year placement, empty states, photo/initials, read-only access and overflow. Vite build, targeted Pint, Blade syntax and whitespace checks passed. Browser fixtures were rolled back in the isolated test database. Previews: [desktop profile](previews/student-profile-1440.png), [mobile profile](previews/student-profile-390.png).

## Enrollment inside Add/Edit Student

The optional Enrollment summary sits after student essentials. Mobile order is student details → enrollment → guardians → optional address/photo. Manage enrollment opens a dialog, and Apply stages changes for the main student save. Cancel or Escape restores the previously applied draft, including its selected year. The current active year is selected by default; existing student enrollment years remain available for read-only history. Class choices contain active school classes, with dependent active sections, available seats and full-section labels. Existing occupants retain their own seat when a section is full.

New enrollments default to automatic roll allocation, using the next reserved number across all enrollment statuses in that year/class/section. Allocation occurs under the school lock at save, so displayed suggestions are advisory. Manual overrides use the existing uniqueness/range checks. Joining dates default to admission date or the year start, whichever is later, and must fall within the year and on/after admission. Existing numbers/dates are retained until explicitly edited. Inactive enrollments offer explicit reactivation with the usual capacity and eligibility checks; withdrawn/transferred enrollments and closed years remain read-only.

Academic year changes fetch private school-scoped metadata from `students.enrollment-options`, without navigating away or rebuilding student/guardian controls. Enrollment drafts are cached per year, restored when returning, and refreshed with an explanation if the saved revision changed. Requests cancel stale responses, temporarily disable the main submit action, and restore the previous selection on failure with retry. Validation restores entered text and opens the relevant dialog. Without JavaScript, enrollment controls stay disabled so student-only saves preserve placements.

`StudentEnrollmentRules` validates the nested enrollment payload; root placement IDs remain prohibited. `StudentEnrollmentEditor` prepares the change before student edits and applies it within StudentService's existing transaction and school lock. Student, guardians, enrollment, audit records and photo cleanup remain atomic. `StudentEnrollmentState` protects against stale year/student/enrollment snapshots and checks academic references. Existing enrollment IDs/year/student identities are retained. `EnrollmentService::correctPlacement` is the explicit correction path for unused class/section placements; it rechecks eligibility, marks, result drafts, published results, and promotion source/target links before updating. The original enrollment update endpoint continues to prohibit context replacement. Changes are audited as ENROLLMENT_PLACEMENT_CORRECTED.

The profile's Enroll student action targets Edit Student's enrollment anchor and opens the dialog. Editable enrollment history links select the corresponding academic year. Enrollment is removed from the sidebar; existing enrollment URLs, history and promotion workflows remain available. The original enrollment integration required no migration or new permission grants; the departure extension below adds nullable history fields.

Verification: **91 related tests / 604 assertions passed**, covering student/guardian creation with enrollment, automatic roll allocation and reserved numbers, duplicate/full-section rollback, editable roll/date values, explicit placement correction, marks and promotion protection, closed/exit history, reactivation, stale/concurrent enrollment, foreign-school/permission checks, date correction, invalid payloads, audit rollback and retained drafts. Chromium checks passed at 320, 390, 768 and 1440 pixels, including year switching, per-year drafts, guardian retention, seat states, manual/automatic rolls, fixed/correctable placements, closed years, errors/retry, mobile order and overflow. Previews use fictitious records in a rolled-back testing-database transaction.

Previews: [desktop inline enrollment](previews/student-inline-enrollment-1440.png), [mobile inline enrollment](previews/student-inline-enrollment-390.png).

## Enrollment, withdrawal and transfer dialogs

Add/Edit Student provides a compact enrollment summary with Manage enrollment, Withdraw and Transfer actions. Native dialogs support focus trapping, Escape/backdrop cancellation, scrolling content and a visible action footer on phones. Opening or cancelling dialogs preserves student and guardian fields. Apply stages one enrollment or departure action; a pending summary offers Edit and Cancel pending action. Enrollment and departure changes cannot be staged together. The final Save button names a staged withdrawal or transfer and commits it with student/guardian/photo changes in one transaction.

Withdraw and Transfer require an existing latest active enrollment, an active student and academic year, and no previously recorded source outcome. Dates must fall between the enrollment joining date and the earlier of today or the academic year end. A reason and explicit confirmation are required; destination school is optional for transfers. New students must first save an active enrollment. Existing marks, results, student identity, contacts and enrollment history are retained. The student master status remains independent of the terminal enrollment status.

`StudentDepartureRules` validates the nested command. `StudentDeparture` checks authorization, school scope and the enrollment revision under StudentService's school lock, then reuses `PromotionService` EXIT processing. A whole-form hash on the promotion batch makes exact retries return the prior result without duplicating contacts, audit events or photos. Reusing a confirmation with changed input is rejected. A fresh UUID photo filename prevents retry cleanup from deleting the previously committed upload. Stale confirmations, audit errors and invalid dates roll back the entire student save. Legacy promotion payload hashes retain their existing shape when the optional departure fields are absent.

Migration `2026_10_31_150000_add_departure_details_to_promotion_history.php` adds nullable departure date/destination fields to promotion outcomes and a hidden student-form hash to promotion batches. It has been applied to the application database; no existing student or enrollment rows were changed. No role grants changed. The student profile, enrollment history and promotion history show recorded departure details.

Verification: **104 related tests / 711 assertions passed**, including exact retries with guardian/photo edits, changed retry rejection, stale revisions, eligibility, authorization, school scope, departure dates, enrollment conflicts, full rollback, validation draft recovery and legacy links. Chromium checks passed at 320, 390, 768 and 1440 pixels for staging, cancellation/Escape, unsaved guardian retention, conflict prevention, confirmation, read-only history, metadata retry, validation recovery and no-JavaScript student-only saves. Browser checks use captured fixtures from a rolled-back testing-database transaction and perform no writes.

Manual check: edit an enrolled student, change a guardian field, open Transfer, enter a date/reason and optional destination, confirm and add it to changes. Verify the pending summary and Save changes & transfer button. Cancel the pending action to retain the original enrollment, or save and verify the recorded departure on the profile and history. Repeat with withdrawal and enrollment changes; closed years and already departed enrollments must remain read-only.

Previews: [desktop transfer dialog](previews/student-transfer-modal-1440.png), [mobile transfer dialog](previews/student-transfer-modal-390.png), [desktop pending transfer](previews/student-departure-pending-1440.png), [mobile pending transfer](previews/student-departure-pending-390.png).
