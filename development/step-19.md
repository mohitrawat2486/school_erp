# Step 19 — Marks Verification (plan Phase 17)

## Delivered

Open **Examinations → Marks Verification** to search the school review queue by student name/admission number, exam or subject. Filter by DRAFT, SUBMITTED, VERIFIED or LOCKED; queues paginate at 20 records. Exact exam, exam-subject and section filters are supported, including the shortcut from the entry roster.

Review an individual record's saved marks, absence, remarks and workflow actors/timestamps. Actions are deliberate POST confirmations:

1. **Verify submitted marks**: SUBMITTED → VERIFIED; records verifier/time.
2. **Lock verified marks**: VERIFIED → LOCKED; records locker/time.
3. **Unlock for correction**: LOCKED → DRAFT; requires `marks.unlock` and a nonblank reason of at most 1000 characters. Preserves numeric marks, absence, remarks and entered actor; clears submission, verification and lock approvals. The row must be submitted, verified and locked again.

Every successful action increments the existing row version and writes a transactional audit. Unlock audits retain the complete prior approvals and the reason after current workflow metadata is cleared. No marks values can be changed through review actions. Existing entry rejects all submitted, verified and locked rows; only a draft restored by authorized unlock can be edited with the usual entry permissions and teacher assignment restrictions.

## Files

Created:

- `app/Policies/StudentMarkPolicy.php`
- `app/Services/MarksVerificationService.php`
- `app/Http/Controllers/MarksVerificationController.php`
- `resources/views/marks-review/index.blade.php`
- `resources/views/marks-review/show.blade.php`
- `tests/Feature/MarksVerificationTest.php`
- `tests/Concerns/BuildsMarksContext.php`
- `development/step-19.md`

Modified:

- `app/Models/StudentMark.php`: entered/submitted/verified/locked user relationships.
- `routes/web.php`: authenticated school-scoped review routes.
- `resources/views/components/app-layout.blade.php`: permission-aware verification navigation.
- `resources/views/marks/show.blade.php`: contextual review shortcut for authorized reviewers.
- `tests/Feature/MarksEntryTest.php`: reuses shared marks fixtures extracted into the new trait, retaining its existing test coverage.
- `README.md`: completed phase and next development step.

No schema migration or role-grant changes are required: Phase 16 already created the four statuses, actor IDs and timestamps. No marks or accounts are seeded.

## Permissions and lifecycle

Review access requires `marks.view` plus at least one of `marks.verify`, `marks.lock`, or `marks.unlock`. These are elevated school-wide review permissions; assignment restrictions continue to govern ordinary teacher entry. Each mutation separately requires its matching permission. `marks.verify` does not imply lock or unlock; lock does not imply verify; unlock does not grant entry permission. Assigned teachers and plain marks viewers cannot access the elevated queue.

Actions require an ACTIVE academic year and exam in MARKS_ENTRY. Later exam states and closed/archived years remain read-only, including unlock. Verify and lock also require active exam/term/class, exam subject/curriculum/master subject, enrollment/student/section, and valid submitted components/totals. Lock rechecks verification metadata. Absence can be verified/locked with null numeric marks. Unlock can restore a draft despite inactive parent records; entry still requires those contexts to be resolved.

The exam remains in MARKS_ENTRY while individual rows are reviewed. This intentionally allows other students/subjects/pages to finish entry. Automatically changing the whole exam to VERIFICATION on the first reviewed row would freeze unrelated drafts. Whole-exam completeness, approval and publication belong to the later result workflow; this phase implements the specified row workflow only.

There is no self-review prohibition in the supplied plan, so independently granted permissions determine who may verify/lock. There is no bulk approval, automatic approval or direct return of submitted/verified rows to draft. Corrections to locked marks follow the explicit unlock cycle.

## Integrity and concurrency

Services lock the school and refresh actor, mark and exam before checking permissions, expected source status, academic year and row version. A stale or repeated action fails instead of changing newer workflow state. Marks review does not accept client-supplied status, totals or approval actor fields. All actors/times are derived on the server. Audit failure rolls back status, approvals and version, including unlock.

Verification revalidates component limits, exact server total and submission metadata. Existing school scopes/composite foreign keys remain authoritative for ownership and marks identity. Cross-school route IDs return 404; direct service calls also check ownership. Inactive/historical records stay visible in the authorized queue without being erased from history. Text output, including remarks and names, is escaped.

## Routes

- `GET /examinations/marks-review` — queue; optional `q`, `status`, `exam_id`, `exam_subject_id`, `section_id`.
- `GET /examinations/marks-review/{student_mark}` — review details.
- `POST /examinations/marks-review/{student_mark}` — `action=verify|lock|unlock`, expected `version`, and `reason` for unlock.

## Manual testing

1. Submit marks through Marks Entry in an ACTIVE year and open exam. Confirm first entry moved the exam to MARKS_ENTRY.
2. Sign in with `marks.view` and `marks.verify`. Find a submitted row, inspect its components and confirm verification. Check verifier/time and audit.
3. Sign in with `marks.lock` and `marks.view`. Lock the verified row. Check locker/time; attempts to edit through Marks Entry must fail.
4. A verifier/locker without `marks.unlock` must not see or execute unlock. An unlock-only reviewer must not acquire verification, lock or entry rights.
5. Unlock with a reason. Reject blank/whitespace/overlong reasons. Confirm unchanged marks, DRAFT status, cleared current approvals and preserved reason/prior metadata in the audit log.
6. As the assigned teacher, reload the entry roster, correct the draft and submit again. Verify and lock again. Earlier approvals must not carry over.
7. Open the review record in two tabs. Execute an action in one, then try the stale version in the other; no overwrite or duplicate audit should occur.
8. Try actions in the wrong order, on an inactive year/context or after publication/closure. Historical records remain visible, but unsafe transitions fail.
9. Check absent rows, zero/decimal marks, search/filter combinations, pagination beyond 20 rows, phone table scrolling and desktop list width.
10. Check an ordinary teacher, plain marks viewer, inactive account and another school's IDs. Every action must honor independent permissions and school ownership.

## Verification

- Full regression: **338 tests, 2303 assertions passed**.
- Targeted marks entry/review suites: **45 tests, 286 assertions passed**, including 20 new verification tests covering the full correction cycle, independent permissions, absence, wrong-order actions, stale/replayed versions, inactive context, publication restrictions, audit rollback, escaped remarks, search/pagination and school isolation.
- Vite production build, Blade compilation, targeted Pint and whitespace checks passed.
- Browser checks: seven review pages at 320, 390, 768, 1440 and 1920 pixels; **35 page/viewport checks passed**, with no document overflow or JavaScript errors. Verified correct action/version for each state, required unlock reason, read-only published/draft details, pagination and one active sidebar link.
- Visually reviewed [mobile unlock form](previews/marks-review-locked-390.png) and [desktop queue](previews/marks-review-queue-1440.png). Also saved [desktop unlock form](previews/marks-review-locked-1440.png).
- Preview fixtures were created transactionally in `school_erp_testing` and rolled back. Static previews verify rendering/client behavior; feature/service tests verify mutations and access control. The temporary preview server was stopped afterward.

## Next phase

Plan Phase 18 — Grading: configurable grading schemes, ranges and grade rules without hard-coded grading logic.
