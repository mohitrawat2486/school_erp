# Step 16 — Examination Module (plan Phase 14)

Follow-on Step 17 adds exam-subject configuration: configure at least one valid active exam subject before opening an exam. The original setup-only behavior below describes the Phase 14 implementation.

## Completed

School/year exam terms and class-specific examinations with searchable paginated selection, directories, details, draft editing, term activation/deactivation and controlled setup transitions. All lists/pickers fill the workspace width and reuse the established responsive table/filter components. Exam terms are reusable within a year; exam year, term and class are fixed after creation. No exam subjects, marks, results or publication workflow is implemented in this phase.

## Files created

- `database/migrations/2026_10_27_230000_create_exam_terms_and_exams.php`
- `app/Enums/ExamStatus.php`, `ExamTermStatus.php`
- `app/Models/Exam.php`, `ExamTerm.php`
- `app/Policies/ExamPolicy.php`, `ExamTermPolicy.php`
- `app/Support/ExamSetup.php`
- `app/Services/ExamService.php`, `ExamTermService.php`, `ExamSetupSelection.php`, `ExamDirectory.php`
- `app/Http/Requests/SaveExamRequest.php`, `SaveExamTermRequest.php`, `SelectExamSetupRequest.php`, `ExamIndexRequest.php`
- `app/Http/Controllers/ExamController.php`, `ExamTermController.php`
- `resources/views/exams/index.blade.php`, `select.blade.php`, `form.blade.php`, `show.blade.php`
- `resources/views/exam-terms/index.blade.php`, `form.blade.php`, `show.blade.php`
- `resources/views/components/exam-table.blade.php`
- `tests/Feature/ExaminationTest.php`

Modified: School/AcademicYear/SchoolClass relationships, AcademicYearService consistency checks, application sidebar and routes, README and built frontend assets.

## Database changes and relationships

`exam_terms`: school, academic year, name, ACTIVE/INACTIVE status and timestamps. Composite school/year foreign key; unique school/year/name; school/year/id and school/id reference keys; school/status/year index. Term status is checked by the database.

`exams`: school, academic year, exam term, class, name, start/end dates, DRAFT status and timestamps. Composite references enforce school ownership and term/year consistency even outside application code. Unique school/year/term/class/name allows differently named examinations for a class/term without duplicate identities. School/id and school/year/class/id reference indexes prepare future exam-subject and marks relationships; listing indexes cover school/year/class/status and school/term/status. Dates must be ordered; statuses are constrained to DRAFT, OPEN, MARKS_ENTRY, VERIFICATION, APPROVED, PUBLISHED and CLOSED.

Exam belongs to School, AcademicYear, ExamTerm and SchoolClass. ExamTerm belongs to School/AcademicYear and has many Exams. School and AcademicYear have many terms/exams; SchoolClass has many exams. AcademicYear relations include soft-deleted records for historical reads. Foreign keys restrict physical parent deletion. No term/exam deletion route exists.

## Rules and status transitions

DRAFT or ACTIVE academic years allow setup. Creation requires an active term and class in the selected year. Terms are unique by name within a school/year. Exam names are unique within year/term/class. Date range must be ordered and wholly inside the academic year; same-day exams are allowed. Name/dates can only be edited while the exam is DRAFT. Year, term and class are immutable after creation.

Available setup transitions:

| Action | From | To | Conditions |
| --- | --- | --- | --- |
| Open | DRAFT | OPEN | ACTIVE academic year, active term/class, valid dates |
| Return to draft | OPEN | DRAFT | Writable year |
| Close | DRAFT or OPEN | CLOSED | Writable year; closing retains the record and is final |

OPEN freezes setup; return to draft before correcting details. Closing a draft serves as cancelling unused setup. DRAFT/OPEN setup can be closed even when the term/class has become inactive. Closed and archived years are read-only. Term deactivation is blocked by exams in states other than DRAFT/CLOSED. Terms with any non-draft exam cannot be renamed. Reactivation preserves the term/exam records.

All later statuses are represented in the enum/database, but setup endpoints cannot enter, reverse or close MARKS_ENTRY, VERIFICATION, APPROVED or PUBLISHED. Publication permission is not used to bypass setup management. Later phases must add prerequisite checks and their own controlled transitions; OPEN currently denotes released setup, not marks-entry readiness. Returning to draft is currently safe because no marks module exists; future integration must block it once dependent academic records prohibit changes.

AcademicYearService blocks date changes that exclude an existing exam, deletion of years with term/exam setup, and closure while any exam is not CLOSED. This avoids stranding setup behind the year's read-only boundary.

## Authorization and auditing

Existing `exam.view` permits reading. Creation additionally requires `exam.create`; editing, term status changes and setup transitions additionally require `exam.update`. Permissions are independent: creation does not grant management; `exam.publish` does not grant setup management. No role grants or new permissions are introduced.

Requests validate input and prohibit direct school/status/context changes. Route binding uses the existing school scope. Policies check ownership, writable year and draft-only editing. Services independently recheck fresh actor/state under a school lock, lock affected records, validate parent ownership and perform transactions with audits. Audit failure rolls back records and status changes. Unchanged saves/term status requests avoid duplicate audit events. Cross-school route IDs return 404; cross-school posted IDs fail validation or scoped lookup.

## Routes

- `GET /examinations/terms`, `/terms/create`; `POST /examinations/terms`
- `GET /examinations/terms/{exam_term}`, `/edit`; `PUT /examinations/terms/{exam_term}`
- `POST /examinations/terms/{exam_term}/activate`, `/deactivate`
- `GET /examinations/exams`, `/exams/create`; `POST /examinations/exams`
- `GET /examinations/exams/{exam}`, `/edit`; `PUT /examinations/exams/{exam}`
- `POST /examinations/exams/{exam}/open`, `/draft`, `/close`

Directories support `q` and `status`; exact school-scoped `academic_year_id`, `exam_term_id` and `class_id` filters are accepted where applicable. Creating a term selects a year; creating an exam selects year → active term → active class. Searchable pickers paginate at 20 instead of loading unbounded dropdowns. Term details list its class exams and provide a contextual Add class exam link.

## Assumptions / pending

Terms use ACTIVE/INACTIVE; exams have the seven planned statuses. Multiple differently named exams per class/term are supported. Dates have day precision and no scheduling/timetable functionality. Setup is permitted in draft years, but opening requires an active year. Exams do not require enrollment or curriculum allocation yet; exam subjects are the next phase. Closing is final; no reopen/delete workflow is provided. No default terms, exams or permission grants are seeded. The previously documented MySQL server upgrade remains a separate maintenance task.

## Manual testing

1. Use an account with `exam.view`, `exam.create` and `exam.update`. Prepare a DRAFT or ACTIVE academic year and active class.
2. Open **Examinations → Exam Terms → Add exam term**, select the year and enter a term such as Half Yearly. Save and verify its details. Try a duplicate term name in the same year.
3. Open **Examinations → Exam Setup → Add exam**, select year → term → class, enter exam name and dates inside the year, and save. Or use **Add class exam** from term details.
4. Verify DRAFT details; edit name/dates. Try reversed/out-of-year dates and duplicate exam identity. Directly submitting changed year/term/class/status must fail.
5. Expand **Open exam** and confirm. Setup details should become read-only. Return to draft to revise. Opening from a draft year or with inactive term/class must fail.
6. Try renaming/deactivating the term while an exam is OPEN. Verify rejection. Return to draft, then deactivate the term; further exam creation/opening should be blocked until reactivated.
7. Close the exam and verify its history remains. Reopening or editing must fail. A year containing any unclosed exam cannot close; close all exams then close the year and verify all setup is read-only.
8. Verify read-only users can list/view but cannot create/update/transition. Create-only and publish-only users must not acquire update permission. Foreign-school IDs and wrong-year terms must remain inaccessible.
9. Check audit logs with `audit.view`, pagination with more than 20 exams, phone table scrolling and desktop sidebar expansion.

## Next recommended phase

Exam Subjects (plan Phase 15): exam-specific curriculum allocations and theory/practical maximum/passing marks, with validated year/class/subject context.

## Verification

- Migration applied to `school_erp`; no real terms or exams seeded.
- Full regression suite: **270 tests / 1,856 assertions passed** on isolated `school_erp_testing`.
- **28 new examination tests** cover term/exam creation, draft editing and audits; contextual selection; name/date/forbidden-field validation; immutable context; identity uniqueness; controlled open/draft/close transitions; reserved future-state protection; draft-year planning and closed/archived read-only behavior; year closure/date/deletion consistency; active parents and term/year matching; released-term rename/deactivation guards; independent read/create/update/publish permissions; guests/cross-school access; search/status/pagination; audit rollback; stale draft models; and database constraints for cross-year terms, cross-school classes, duplicate terms/exams and reversed dates.
- Production Vite build, Blade compilation, Pint and changed-code whitespace checks passed.
- Chromium checked 13 term/exam screens at 320, 390, 768, 1440 and 1920 pixels. Checks covered document overflow, active navigation, required fields, immutable edit context, OPEN/read-only setup controls and real server pagination. Preview records were created inside a rolled-back testing-database transaction.

Previews: [exam directory](previews/exams-desktop.png), [exam form](previews/exam-form-desktop.png), [mobile directory](previews/exams-mobile.png).
