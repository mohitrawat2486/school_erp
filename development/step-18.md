# Step 18 — Marks Entry (plan Phase 16)

## Delivered behavior

Open **Examinations → Marks Entry**. Browse the subject directory or choose **academic year → exam and its fixed class → subject → section**. All pickers and the active student roster are paginated at 20 rows. Search by exam/subject name or subject code; exact year, exam and class filters are retained in links.

Enter theory/practical marks, absence and optional remarks. **Save draft on this page** permits missing components. **Submit editable rows on this page** requires every configured component or an explicit absence for each editable row. Each action applies to editable rows on the current page; submitted rows are displayed without inputs and omitted from later batches. Save before changing pages. Pagination does not automatically submit other pages.

Totals are computed on the server in integer hundredths using the shared marks helper. The browser displays a live preview. Blank marks are incomplete; zero is valid. Unused components stay null. Absence requires both components and total to be null; the browser clears/disables marks when absence is selected. Unchecking absence requires marks to be re-entered. Remarks support at most 500 characters and are escaped in views.

The first successful save or submission moves an OPEN exam to MARKS_ENTRY and audits the transition. Exam setup stays frozen. Submitted rows are read-only. Later verification, locking and unlocking operations belong to plan Phase 17; no such endpoints are exposed here.

## Files

Created:

- `database/migrations/2026_10_28_010000_create_student_marks_table.php`
- `app/Enums/StudentMarkStatus.php`
- `app/Models/StudentMark.php`
- `app/Services/MarksAccess.php`
- `app/Services/MarksEntryService.php`
- `app/Http/Controllers/MarksEntryController.php`
- `resources/views/marks/index.blade.php`
- `resources/views/marks/select.blade.php`
- `resources/views/marks/show.blade.php`
- `tests/Feature/MarksEntryTest.php`
- `development/step-18.md`

Modified:

- `app/Models/Student.php`, `StudentEnrollment.php`, `ExamSubject.php`, `Exam.php`: marks history relationships.
- `routes/web.php`: marks routes within existing authenticated school middleware.
- `resources/views/components/app-layout.blade.php`: Marks Entry sidebar link and examination group for users without exam setup access.
- `resources/js/app.js`: accessible form total/absence previews, with server validation authoritative.
- `README.md`: phase completion and next step.

## Schema and integrity

`student_marks` stores the plan fields: school, exam, student, subject, theory/practical/total marks, absence, remarks, status, entered/submitted/verified/locked actors and timestamps, plus created/updated timestamps. It also stores academic year, class, exam subject, enrollment identity and `lock_version`.

- Unique `(school_id, exam_id, student_id, subject_id)` prevents duplicate marks across sections.
- Composite foreign keys bind exam to school/year/class, exam subject to school/exam/subject, and enrollment to school/year/class/student. A supporting enrollment reference index is added by this migration.
- Actor foreign keys include school; unrelated school accounts cannot be stored as marks actors.
- Deletion is restrictive. Existing enrollments, subjects and exams with marks cannot be physically removed through foreign key cascades.
- Decimal components allow 0–9999.99; total supports the sum of both components. Database checks enforce nonnegative values, total arithmetic, absence consistency, controlled statuses and submitted actor/time/completion metadata. Component maximum/completeness validation depends on exam configuration and runs in the service.
- DRAFT, SUBMITTED, VERIFIED and LOCKED are represented. Only draft and submission actions are available in this phase; verification/locking metadata is reserved and null.

Migration adds schema/indexes only; no marks or permissions are seeded. Rollback drops marks before removing its supporting enrollment reference index.

## Authorization

Existing permissions are reused without changing role grants:

- `marks.view`: directory, selection and roster access.
- `marks.enter`: creation/save/submit actions.
- `marks.update`: additionally required when modifying or submitting an existing draft.
- `marks.submit`: additionally required for submission.
- `marks.verify` **or** `marks.lock`: explicit elevated access across school classes/sections/subjects, still requiring `marks.view` and each applicable action permission.

Other users require an effective SUBJECT_TEACHER assignment for the exact year/class/curriculum allocation and requested section. Null section assignments cover the class's sections. CLASS_TEACHER alone grants no subject marks entry. The account's employee must match the assigned teacher's employee. Existing effective-assignment rules require active year, teacher, employee, class, optional section and curriculum/subject. Disabled users and schools are denied. Directory and picker queries use correlated assignment checks, rather than exposing other teachers' contexts or loading unbounded assignment lists.

Services refresh actor, exam, subject, section and roster under the existing school transaction lock. School scope and authorization are rechecked independently of HTTP middleware. School-owned submitted identities and actor fields are derived server-side; row input allows only enrollment, version, marks, absence and remarks. Server totals/status/actors cannot be supplied through rows.

## Entry rules and concurrency

Entry requires an ACTIVE academic year, OPEN/MARKS_ENTRY exam and active term/class/section/exam subject/curriculum/master subject. Existing exam readiness validation is reused before saving, including every active exam subject. Only ACTIVE enrollments with ACTIVE students in the exam year/class and selected section are eligible.

Each batch contains 1–20 distinct enrollments and is atomic with its audits. Failure in any row or audit rolls back all marks and the exam transition. Draft no-ops do not increment versions or write duplicate audits. Row versions start at 1; new rows submit expected version 0. Stale drafts and simultaneous creates are rejected with a reload message. Validation redirects retain the submitted version, and old values are restored only for the same enrollment, preventing stale retries or roster reordering from overwriting another row. Submitted/verified/locked rows cannot be changed through this service, even with elevated access.

Historical rows remain linked to their enrollment. Deactivated students/enrollments are excluded from the active entry roster; their saved marks are retained in the database. Section transfers within the same class/year retain the enrollment's marks. Any future enrollment transfer workflow must preserve this behavior or explicitly review its marks implications. No student-specific optional-subject selection is introduced; active students in the section form the roster for configured exam subjects.

## Routes

- `GET /examinations/marks` — searchable subject directory; optional `academic_year_id`, `exam_id`, `class_id`.
- `GET /examinations/marks/select` — paginated years; optional `academic_year_id` selects paginated exams with class context.
- `GET /examinations/marks/{exam_subject}` — sections; append `section_id` for paginated roster and `page` for later rows.
- `POST /examinations/marks/{exam_subject}` — `section_id`, `action=draft|submit`, and editable `rows` with expected versions.

## Manual checks

1. Prepare an active academic year, class/section, curriculum, exam term, draft exam and valid active exam subject. Open the exam and enroll active students in the same year/class/section.
2. Visit Marks Entry, choose year/exam/subject/section and confirm roll-number ordering. With more than 20 students, verify pagination and save each page separately.
3. Save one component as a draft. Confirm total is incomplete; complete the other component and confirm the exact sum. Try zero and decimal values.
4. Check absence: marks clear and total says Absent. Save or submit; stored components/total must be null. Uncheck on a draft and enter marks again.
5. Reject negative marks, over-maximum values, three decimals, values in unused components, oversized remarks and absence with supplied marks.
6. Submit complete rows. Confirm submitted actor/time and audit records, read-only controls and rejection of direct edits. Check a page containing both submitted and unentered rows.
7. Open a draft in two tabs; save one and then try the other. The second save must reject stale versions without overwriting marks.
8. Sign in as an assigned subject teacher. Verify exact subject/section restrictions, class-wide assignment behavior and denial after assignment/teacher/employee deactivation. A class teacher without a subject assignment must not gain access.
9. Test read-only elevated accounts, missing action permissions, another school's IDs, inactive roster members and closed/later exam states. Bulk failures must preserve all prior values.
10. Check phone table scrolling, mobile navigation and full-width desktop lists. No actual marks are automatically seeded.

## Verification

Targeted suite: **25 tests, 156 assertions passed**. Covers drafts, exact totals, submission/absence, immutable submitted rows, permission and assignment scope, inactive parents/accounts, wrong context, optimistic concurrency, atomic rollback, no-op auditing, pagination and database constraints. Build and Blade compilation passed. Full regression and browser results are recorded below.

## Next phase

Plan Phase 17 — Marks Verification: submitted → verified → locked, authorized audited unlock, and related review workflows.

### Browser and build results

- Seven rendered pages (directory, year picker, exam/class picker, section picker, first roster page, second roster page and closed-exam roster) checked at 320, 390, 768, 1440 and 1920 pixels: **35 page/viewport checks passed**. No document overflow or JavaScript errors occurred.
- Verified live totals including zero, incomplete values, absence clearing/disabling components, submitted rows without editable inputs, roster pagination and closed-exam read-only controls. Exactly one sidebar item is active per page.
- Preview fixtures used only `school_erp_testing` inside a rolled-back transaction. Static previews exercise rendering/client behavior; HTTP/service tests exercise saving and authorization.
- Vite production build, Blade compilation, targeted Pint and whitespace checks passed. Schema migration applied successfully to the local application database; no marks data seeded.
- [Desktop roster](previews/marks-roster-1440.png), [mobile roster](previews/marks-roster-390.png), [desktop directory](previews/marks-list-1440.png).

Final full regression: **318 tests, 2173 assertions passed**, including the new validation-redirect regression. Local preview server stopped after verification.
