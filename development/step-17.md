# Step 17 — Exam Subjects (plan Phase 15)

## Completed

Exam-specific subject configuration from the existing year/class curriculum, with copied marks defaults, theory/practical maximums, explicit passing marks, activation/deactivation, auditing and draft-only editing. Added a full-width searchable directory, paginated curriculum picker, configuration/details screens, sidebar link and subject table on exam details. Marks entry is not implemented in this phase.

## Files created

- `database/migrations/2026_10_28_000000_create_exam_subjects_table.php`
- `app/Enums/ExamSubjectStatus.php`, `app/Models/ExamSubject.php`
- `app/Policies/ExamSubjectPolicy.php`
- `app/Support/ExamMarksConfiguration.php`
- `app/Services/ExamSubjectService.php`, `ExamSubjectDirectory.php`
- `app/Http/Requests/ExamSubjectIndexRequest.php`, `SelectExamSubjectRequest.php`, `SaveExamSubjectRequest.php`
- `app/Http/Controllers/ExamSubjectController.php`
- `resources/views/exam-subjects/index.blade.php`, `select.blade.php`, `form.blade.php`, `show.blade.php`
- `resources/views/components/exam-subject-table.blade.php`
- `tests/Feature/ExamSubjectTest.php`

Modified: Exam/ClassSubject/Subject/School relationships; ExamService opening readiness checks; ExamController/details; sidebar/routes; existing ExaminationTest setup fixtures; README; generated frontend assets. Shared curriculum marks rules are reused without changing their optional-default behavior.

## Database changes

`exam_subjects` stores school, exam, class-subject allocation, academic year, class, subject, nullable theory/practical maximums, required passing marks, ACTIVE/INACTIVE status and timestamps. Year/class/subject identity is copied from the validated allocation, never trusted from submitted fields.

- Composite exam foreign key ensures school/year/class match the selected exam.
- Composite curriculum foreign key additionally ensures subject and allocation match the same school/year/class.
- Unique school/exam/allocation and school/exam/subject prevent duplicate configuration, including inactive records.
- School/id and school/exam/subject/id reference keys prepare exact future marks references.
- Listing indexes cover school/exam/status and school/allocation.
- Checks constrain status, nonnegative marks, positive combined maximum and passing marks no greater than the total. Passing marks are not nullable.
- All parents use restrictive foreign keys; there is no physical-delete route.

ExamSubject belongs to Exam, ClassSubject, Subject and School. Those parents have many exam subjects. No student placement or marks-entry tables are created.

## Marks configuration

Curriculum fields are copied once as form defaults. A user may override them for the exam without modifying curriculum. Existing exam configuration is independent of subsequent curriculum edits.

Theory-only, practical-only or combined components are supported. An unused component may be blank; explicit zero is allowed if the combined maximum is positive. Passing marks must be explicitly set; zero is allowed. Each field is bounded to 0–9999.99 with at most two decimal places. The existing MarksConfiguration helper performs totals/comparisons using integer hundredths, avoiding floating-point boundary errors. ExamMarksConfiguration adds exam readiness requirements while preserving optional curriculum defaults elsewhere.

For example: Mathematics 100 theory / no practical / 33 passing, or Computer 70 theory / 30 practical / 33 passing. Maximum marks are computed on the server from the saved exam configuration.

## Authorization and lifecycle

Reading requires existing `exam.view`. Adding/editing/status changes require `exam.update` and a DRAFT exam in a DRAFT/ACTIVE academic year. `exam.create` alone does not grant subject management; no new permission grants are introduced. Exams in OPEN, MARKS_ENTRY, VERIFICATION, APPROVED, PUBLISHED or CLOSED freeze subject setup. Closed/archived years remain read-only.

Creation/reactivation requires active allocation, subject, class and term with exact exam year/class context. Already configured allocations, including inactive records, are excluded from the picker; use the existing record to reactivate. Existing draft marks may be corrected or deactivated when a parent is inactive, retaining historical identity. Exam, allocation, year, class and subject cannot be moved on an existing configuration.

Requests validate marks and prohibit identity/school/status overrides. Route binding uses school scope; policies check ownership. Services recheck fresh actor and exam state under a school lock, validate and lock context, write transactionally and audit effective changes. Audit failures roll back changes. Unchanged save/status requests do not add duplicate audit events. Stale draft models cannot bypass an exam opened by another request.

Opening an exam now requires at least one ACTIVE exam subject. Every active record must have valid marks and an active curriculum allocation/subject; existing ExamService checks active year/term/class. Inactive exam subjects are excluded from readiness. Return to draft before changing configuration. Existing OPEN exams are not automatically rewritten by migration; later marks-entry readiness should reuse the same validation before allowing entry.

## Routes

- `GET /examinations/exam-subjects` — search by subject name/code or exam, status and optional exact `exam_id`, `academic_year_id`, `class_id` filters
- `GET /examinations/exams/{exam}/subjects/create` — paginated curriculum selection; append `class_subject_id` for the final marks form
- `POST /examinations/exams/{exam}/subjects` — save configuration
- `GET /examinations/exam-subjects/{exam_subject}`, `/edit`
- `PUT /examinations/exam-subjects/{exam_subject}` — marks correction
- `POST /examinations/exam-subjects/{exam_subject}/activate`, `/deactivate`

Exam details include paginated subject configuration using `subjects_page`; no unbounded curriculum dropdowns are loaded. Lists/pickers use the full workspace width with phone scrolling tables; forms retain a readable width.

## Assumptions / potential issues

Passing marks are required for exam readiness, even if the curriculum default is blank. Zero passing marks must be entered deliberately. Parent deactivation does not overwrite existing exam marks; it blocks reactivation/opening until resolved. Core/optional subject selection for individual students remains outside this phase. No subject defaults, exams, actual marks or role grants are automatically seeded. Migration filename uses the next chronological day after the existing 23:00 migration; it is applied by the normal migration command. The previously documented MySQL server upgrade remains separate.

## Manual testing

1. Prepare a DRAFT exam in a DRAFT/ACTIVE year, active term/class, and active curriculum allocations in that same year/class. Sign in with `exam.view` and `exam.update`.
2. Open **Examinations → Exam Setup**, view the exam and click **Add exam subject**. Search/select a curriculum subject. Confirm copied defaults or enter exam-specific values and save.
3. Verify theory/practical/total/passing values in exam details and **Examinations → Exam Subjects**. Edit while draft and confirm curriculum defaults are unchanged.
4. Try 100 theory only; practical only; 70+30; or 99.99+0.01 with passing 100. Verify exact totals. Blank all maximums, zero total, negative/over-limit values, three decimals, missing passing marks and passing above total must fail.
5. Deactivate the record and verify it remains in history, cannot be duplicated, and is excluded from the selection picker. Reactivate it. Repeat while a parent is inactive to verify reactivation fails.
6. Try opening an exam with no active subjects, then with an inactive curriculum subject. Opening must fail. Resolve configuration and open the exam; marks edit/status controls should disappear and direct mutations must be rejected.
7. Return the exam to draft to revise configuration. Closed/archived years and all later exam states must remain read-only.
8. Change curriculum maximums separately and verify saved exam marks remain unchanged. Attempts to supply another class/year allocation or change an existing subject's identity must fail.
9. Verify read-only/create-only users cannot manage subjects, cross-school IDs are inaccessible, and audit logs record changes. Check table/picker pagination beyond 20 records and mobile scrolling.

## Next recommended phase

Marks Entry (plan Phase 16): exam/enrollment/subject marks, bulk drafts, submission, absence and component validation, using existing teacher-assignment authorization.

## Verification

- Full regression suite: **293 tests, 2017 assertions passed**, including 23 new exam-subject tests. Targeted examination and exam-subject suites: 51 tests, 369 assertions passed.
- Migration applied successfully. Vite build, Blade compilation, targeted Pint formatting and whitespace checks passed.
- Resumed browser verification: six rendered pages (directory, details, edit, curriculum picker, new configuration and exam details) checked at 320, 390, 768, 1440 and 1920 pixels: **30 checks passed**, with no document overflow or JavaScript errors. Confirmed 20-row pagination, copied curriculum defaults, required passing marks and independent saved exam marks.
- Browser fixtures were created inside a transaction in `school_erp_testing` and rolled back. Temporary preview pages do not submit forms; mutation and authorization behavior is covered by the HTTP/service tests above.
- Visually reviewed [mobile list](previews/exam-subject-list-390.png) and [desktop marks form](previews/exam-subject-edit-1440.png). Also saved a [desktop directory preview](previews/exam-subject-list-1440.png).
