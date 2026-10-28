# Step 22 — Result Approval (plan Phase 20)

## Delivered

Open **Results → Result Approval**. Choose an exam accepting marks, choose an active grading scheme and confirm generation. Generation requires complete LOCKED marks for every active student and configured subject, calculates the full class with the existing calculation service, saves a revision for review and moves the exam to VERIFICATION.

Review paginated student totals and individual subject details. Confirm approval, then publication using independently granted permissions. Approval changes the batch/exam to APPROVED; publication changes both to PUBLISHED. Saved values are displayed throughout; pages do not recompute grades from live configuration.

**Return for correction** requires a reason and `result.approve`. It retains the old revision as RETURNED and moves the exam back to MARKS_ENTRY. Authorized marks staff may then unlock/correct/resubmit/verify/lock rows and generate a new revision. Published batches are terminal; they cannot be returned or regenerated through this workflow.

## Files

Created:

- `database/migrations/2026_10_28_030000_create_result_approval_batches.php`
- `app/Models/ResultBatch.php`
- `app/Models/ResultDraft.php`
- `app/Policies/ResultBatchPolicy.php`
- `app/Services/ResultApprovalService.php`
- `app/Services/ResultSourceFingerprint.php`
- `app/Http/Controllers/ResultApprovalController.php`
- `resources/views/results/index.blade.php`
- `resources/views/results/create.blade.php`
- `resources/views/results/show.blade.php`
- `resources/views/results/student.blade.php`
- `tests/Feature/ResultApprovalTest.php`
- `tests/Concerns/BuildsResultContext.php`
- `development/step-22.md`

Modified:

- `app/Models/Exam.php`: result batch history relationship.
- `app/Services/ExamService.php`, `resources/views/exams/show.blade.php`: permit closing a PUBLISHED exam only when it has a published batch, preserving results and permitting normal academic-year closure.
- `routes/web.php`, `resources/views/components/app-layout.blade.php`: school-scoped results routes and permission-aware sidebar navigation.
- `tests/Feature/ResultCalculationServiceTest.php`: shared result fixtures extracted to the new trait; calculation assertions retained.
- `README.md`: completed phase and next step.

## Persistence and publication boundary

`result_batches` stores school/year/class/exam/scheme identity, revision, optimistic lock_version, GENERATED/APPROVED/PUBLISHED/RETURNED status, calculation context, source/payload hashes, workflow actors/times and optional return reason. Composite foreign keys retain school ownership and exam context. Unique school/exam/revision preserves revision history. A generated nullable `active_exam_id` plus unique school/active-exam index allows only one current GENERATED, APPROVED or PUBLISHED batch, while retaining any number of returned revisions.

`result_drafts` stores school/batch/student/enrollment identity and a JSON payload containing the calculated totals, grading values, source versions and subject results. Enrollment references are school/student-specific. Each enrollment occurs once per batch. Published rows remain in this same table; its name reflects the generation stage, not permission to edit a published result.

The persisted payload includes original student/subject/section names, exam/year/class/scheme names, marks versions, grade labels/points/ranges, totals, status and rank. Result pages render these copies, so later name/grading changes do not rewrite the reviewed or published values.

Plan Phase 21 will add normalized `student_results` and `student_result_subjects` publication snapshots and their dedicated read/version contracts. This phase already retains durable immutable application payloads to make actual publication safe; it does not publish a pointer to mutable grading configuration or defer persistence until that later phase. Phase 21 must normalize the approved saved payload, never recalculate historical published results from current marks/rules.

Generated student payloads reject Eloquent updates/deletes; batch deletion is rejected, and published batches reject model updates. No HTTP editing/deletion endpoints exist. These are application-level immutability controls; arbitrary direct database administration remains outside the application workflow. Database checks enforce controlled statuses, revisions and required workflow actors/times, and restrictive foreign keys retain referenced history.

## Permissions and lifecycle

- `result.view`: read directory, batch and student results.
- `result.generate`: additionally required for generation/selectors.
- `result.approve`: additionally required for approval and return for correction.
- `result.publish`: additionally required for publication.

No permission implies another action permission, and approval/publication do not require `result.generate`. Result permissions intentionally provide school-wide results access; ordinary teacher assignment rules continue to apply to marks entry. No role grants are added automatically.

Generation is class-wide only, with class ranks calculated over the entire cohort rather than a page. School/year/class/exam/subject context, active grading configuration, active roster and full locked workflow metadata are validated by the existing calculation service. Failed or absent results may be published when their marks are complete; publication does not require every student to pass.

Review/approval freeze marks because the exam is no longer in MARKS_ENTRY. Corrections require an explicit reason of 1–1000 nonblank characters and returning the batch. New generation creates the next revision and does not overwrite prior payloads/approvals. Closed/archived years and mismatched exam states deny transitions.

A PUBLISHED exam with a published batch can subsequently be closed through existing Exam Setup controls (`exam.view` + `exam.update`). This changes only exam lifecycle status; batch publication and payloads stay intact. Once all exams are closed, the academic year can close normally, and authorized result reading remains available.

## Freshness and integrity

All supported mutations serialize under the existing school transaction lock. Services refresh the actor and owned models, recheck action permissions/source status and compare the expected batch version. Stale/replayed actions fail without duplicate audits.

Generation captures a canonical SHA-256 source hash over exam context, year/term/class, grading scheme/version/rules, active subjects and their curriculum/master-subject status, active student/enrollment/section identity, and all marks for the exam including workflow metadata/versions. Exam status is deliberately excluded because approval changes it; expected status is checked separately.

Before approval and publication, the source is read again under the same lock. Changes to marks, active roster, names, section/context status or grading configuration require return/regeneration. Even changes to retained marks outside the active roster conservatively invalidate review. The payload hash is independently verified, with database student/enrollment identities checked against the saved payload, so missing/changed rows cannot silently pass review.

Hashes are canonicalized to survive MySQL JSON key reordering. They detect stale or accidentally modified generated data; they are not a cryptographic signature against a database administrator who can rewrite both data and hashes.

Generation, all student rows, exam transitions and audit events are transactional. Audit failure rolls back the whole operation. Approvals, publication and return record actor/time and effective changes; the return reason is retained and audited.

Generation currently runs synchronously for a class cohort using the calculation service's chunked roster reads; saved results and fingerprint inputs are still materialized in memory. The service boundary supports future queue-backed generation for unusually large cohorts. PDF/export job architecture remains in the later plan phases.

## Routes

- `GET /results` — paginated exam-name/status directory.
- `GET /results/create` — paginated exam selection, then active scheme selection with `exam_id`; append `grading_scheme_id` for confirmation.
- `POST /results` — trusted generation from selected exam/scheme IDs.
- `GET /results/{result_batch}` — actors/times, correction reason, workflow confirmations and paginated student results.
- `GET /results/{result_batch}/students/{result_draft}` — saved totals and paginated subject results; nested ownership is enforced.
- `POST /results/{result_batch}` — `action=approve|publish|return`, expected `version`, and reason for return.

All routes use authenticated school context and `result.view`; policies/services apply action permissions. Cross-school IDs are inaccessible. Values, status and approval actors cannot be supplied through review actions; every result value comes from the calculation service.

## Manual testing

1. Prepare an ACTIVE year/class/section, active grading scheme, exam and active student roster. Submit, verify and lock every subject mark.
2. Open Results → Result Approval → Generate result batch. Choose exam and scheme; confirm. Missing/unlocked rows must prevent generation without partial data.
3. Review saved totals and individual subject details. Check full-class ranks, absence/failure and grading values; verify pagination beyond 20 students.
4. Generate twice: the duplicate must fail. A generator without approval rights cannot approve. An approver without publication rights cannot publish.
5. Approve, then publish using an account with the independent permission. Publication before approval must fail.
6. Before publication, add/change roster/configuration or marks through an authorized correction path. Approval/publication must reject stale source. Return with a reason and regenerate.
7. Return a generated/approved batch, unlock and correct marks, resubmit/verify/lock, and generate revision 2. The original revision must retain its old values and correction reason.
8. Open two tabs and submit a stale batch version. Reject it without overwriting status or duplicating audit events.
9. Published batches must show no mutation controls and reject return/regeneration. Changing live names/grading later must not change saved student results.
10. Close the published exam, then its academic year. Confirm published results remain readable and unchanged.
11. Try another school's IDs, a student row from a different batch, read-only users and inactive accounts. Verify audit actor/timestamps and rollback behavior.
12. Check mobile scrolling/collapsible navigation and full-width desktop lists. No application demo results are seeded.

## Verification

- Full regression: **438 tests, 2726 assertions passed**, including **25 new approval tests**.
- Targeted approval/examination suites: **53 tests, 353 assertions passed**. Covered generation, separate action permissions, correction cycles, revision history, missing/unlocked marks, stale data and versions, identity/payload tampering, publication immutability, database constraints, audit rollback, pagination, school isolation and published exam/year closure.
- Migration applied successfully. Vite build, Blade compilation, targeted Pint and whitespace checks passed.
- Ten rendered pages at 320, 390, 768, 1440 and 1920 pixels: **50 page/viewport checks passed**, with no document overflow or JavaScript errors. Checked workflow controls, required reason, confirmation, saved values, 20-row pagination and one active sidebar link.
- Visually reviewed [desktop review](previews/result-approval-review-1440.png) and [mobile published results](previews/result-approval-published-390.png). Also saved [desktop directory](previews/result-approval-index-1440.png) and [student details](previews/result-approval-student-1440.png).
- Preview fixtures ran only inside a rolled-back transaction in `school_erp_testing`. Static previews test rendering; feature/services test mutations and access. The temporary preview server was stopped after verification. No application demo results were seeded.

## Next phase

Plan Phase 21 — Result Snapshot: normalized immutable `student_results` and `student_result_subjects`, publication versions and a historical read contract based on saved approved payloads.
