# Step 15 — Student Promotion (plan Phase 13)

## Completed

The Students directory provides **Move to next academic year**, a guided bulk Promote/Repeat dialog with student selection, destinations and a server-validated review. The Promotion sidebar entry is removed. Source and destination outcomes appear in student enrollment history. Individual and bulk operations share this workflow; withdrawal/transfer is managed in Edit Student. Searchable history and batch receipts remain available.

Promotion and repeat create NEW enrollments through the existing EnrollmentService; source student/year/class/section/roll/date/status remain intact. Repeat retains the class in a later year; Promote requires a different class. An authorized user explicitly selects the destination; no class-order, grade or pass/fail rules are hard-coded.

Withdrawal and transfer retain the source enrollment with terminal WITHDRAWN/TRANSFERRED status, require a reason and do not create a destination enrollment. These are enrollment exits, not automatic deactivation of the student master record. Source history, outcome and audits remain available.

## Files created

- `database/migrations/2026_10_27_220000_create_student_promotion_history.php`
- `app/Enums/PromotionAction.php`
- `app/Models/PromotionBatch.php`, `PromotionOutcome.php`
- `app/Policies/PromotionBatchPolicy.php`
- `app/Services/PromotionService.php`, `PromotionSelection.php`, `PromotionDirectory.php`
- `app/Support/PromotionRules.php`
- `app/Http/Requests/PromotionIndexRequest.php`, `SelectPromotionRequest.php`, `StorePromotionRequest.php`
- `app/Http/Controllers/PromotionController.php`
- `resources/views/promotions/index.blade.php`, `select.blade.php`, `form.blade.php`
- `tests/Feature/PromotionTest.php`

The Students integration adds `StudentProgressionController`, `StudentProgression`, `StudentProgressionRules`, `students/partials/progression-dialog.blade.php`, `student-progression.js` and `StudentProgressionTest`. No additional migration or permission grant is required for bulk progression.

Modified: enrollment status enum, StudentEnrollment source/origin relationships, enrollment policy/service terminal-state checks, enrollment controller/details, status badge, sidebar, routes, README and compiled frontend assets.

## Database changes and relationships

`promotion_batches` retains school, UUID confirmation key, canonical request hash, actor, PLACEMENT/EXIT mode, source year/class/section, optional target year/class/section and joined date. Composite foreign keys preserve school and section/class ownership. A unique school/confirmation key supports safe request retries. Shape checks require a complete target context for placement batches and no target context for exit batches.

`promotion_outcomes` retains school, batch, student, source enrollment, optional target enrollment, PROMOTE/REPEAT/WITHDRAW/TRANSFER action, notes and timestamps. Composite source/target foreign keys ensure the same student and school; batch foreign key enforces school ownership. Source enrollment uniqueness prevents repeat processing under a different confirmation key. Target enrollment uniqueness prevents competing origin links. Checks constrain action/target shape.

The enrollment status check expands to ACTIVE/INACTIVE/WITHDRAWN/TRANSFERRED. Existing student/year and roll uniqueness constraints remain. Enrollment gains a school/student/id reference index. Restrictive foreign keys preserve the history. No physical-delete or outcome-edit routes are provided. Migration rollback refuses to discard terminal exit statuses rather than silently rewriting them.

## Authorization, transactions and retries

Existing `student.view` permits reading. Processing additionally requires `student.update`; no permission grants are changed. Policies, school-scoped route binding, validated requests and service-level fresh authorization enforce access. Every selected enrollment must match the chosen source year/class/section and belong to the current school.

Processing locks the school and relevant records, then completes the whole batch in one database transaction. New enrollments reuse school/year/student uniqueness, roll uniqueness, joined-date validation, active parents and section capacity. A failure anywhere (including audit logging) rolls back target enrollments, source exits, outcomes and batch. Effective writes are audited. Duplicate confirmation with the same normalized payload and actor returns the existing receipt without additional writes; different payload/actor with the same key is rejected.

A source must be ACTIVE and not previously processed. A later enrollment for that student blocks outdated source processing. Placement can use ACTIVE or CLOSED source years without modifying closed records; DRAFT/ARCHIVED source years are unavailable. The target must be ACTIVE and begin strictly after the source year ends. Target class/section and promoted/repeating student must be active. Withdrawal/transfer require an ACTIVE source year; closed-year exits are rejected. Historical source classes/sections may be inactive because placement does not change them.

Terminal exits cannot be edited, activated or deactivated using ordinary enrollment controls, including with stale model instances. No reversal/correction workflow for completed promotion outcomes is included; review the confirmation carefully. Student master activation remains separately managed. A manual later admission/enrollment can represent a future return; it does not rewrite the exit.

## UI and limits

Open **Students → Move to next academic year**. The modal defaults to the current year and the list's selected class/section. Source years can be active or closed. Source choices retain inactive sections only when active historical enrollments still need processing; destinations require active classes and sections. Already processed, inactive and superseded enrollments show their readiness reason and cannot be selected.

Selections persist across roster search and pagination. Select eligible students on a page, or choose individuals; changing source year/class/section clears selection with an explanation. Students default to Promote. Choose the next active academic year, common joining date and default promoted class/section. Individual Repeat decisions retain the source class and require a section in that class. Blank roll numbers allocate automatically; manual values are reserved first so automatic allocation avoids them and previously reserved numbers across all statuses. The same batch supports promoted students and repeaters in different sections. Phones show decision/review cards with destinations and rolls visible; the dialog footer stays within the viewport.

The roster loads 20 records per request. Select up to 100 across pages per batch; larger cohorts use multiple batches. The review displays exact placements, roll numbers and promoted/repeating/left-for-later counts without writing data. An explicit checkbox enables confirmation. After success, Process remaining students reloads readiness and clears the completed selection. No queue or new dependency is required. Without JavaScript, guidance is shown and no bulk action is submitted.

`StudentProgression` validates eligibility, destination ownership, capacity and dates under the school lock. Review returns an encrypted token bound to the actor and school, expiring after 30 minutes before first use. Confirmation rechecks source revisions, destination metadata and roll/seat reservations against the reviewed snapshot before calling the existing `PromotionService` inside the same transaction. Changed details require a fresh review. Exact retries return the original batch, including after a lost response; changed selections cannot reuse its operation key. Per-row destination sections support mixed Promote/Repeat decisions while preserving legacy canonical hashes when the optional field is absent. Batch header destination fields describe defaults; outcomes retain each student's exact destination.

## Routes

- `GET /promotions` — retained outcome history and search
- `GET /students/progression/roster` — authorized, school-scoped searchable/paginated readiness
- `POST /students/progression/review` — validate and preview without writes
- `POST /students/progression` — confirm the reviewed encrypted token atomically
- `GET /promotions/create` — redirects to the Students modal, retaining source context
- `GET /promotions/create?mode=EXIT` — redirects to Students; with a source enrollment, opens Edit Student
- `POST /promotions` — legacy confirmed processing endpoint retained for compatibility
- `GET /promotions/{promotion}` — batch receipt with paginated outcomes

## Manual testing

1. Prepare an ACTIVE or CLOSED source year, source enrollments, and an ACTIVE target year beginning after the source year ends. Activate target classes/sections and ensure available capacity.
2. Sign in with `student.view` and `student.update`. Open **Students → Move to next academic year** and choose the source year/class/section.
3. Select students across search/pages, continue, choose the next year and promoted class/section, and leave roll numbers automatic or enter manual values. Review the exact destinations and roll numbers, check confirmation, then record the moves.
4. Verify each student has a new target-year enrollment and unchanged source placement. Open either enrollment to follow its origin/outcome link. Retrying the same submission should return the original batch; a new operation cannot reprocess the same source.
5. In the same selection, change an individual decision to Repeat and choose a section in the source class. Verify the review includes both destinations. A repeat-only selection must also work without a promoted destination.
6. For an individual, select just that student in the bulk workflow. Existing enrollment shortcuts open the same dialog with source context and student preselected.
7. Record withdrawal/transfer from Edit Student's departure dialogs. Verify retained source statuses and no new enrollments. Ordinary edit/reactivation should be unavailable.
8. Try a duplicate target roll, full section, inactive target/student, pre-existing later enrollment, invalid joined date or duplicate source. Verify no partial batch is saved.
9. Verify a view-only user can read history but cannot process; foreign-school IDs must remain inaccessible. Check corresponding audit events using `audit.view`.
10. Check phone decision/review cards, roster/history pagination and validation feedback. Search/page changes must preserve selection; source context changes must clear it. Change destination capacity or reserve a roll after review and verify confirmation requests a fresh review without partial writes.

## Next recommended phase

Examination Module (plan Phase 14): school/year exam terms and class-specific examinations, statuses, authorization and validation.

## Verification

- Migration applied to `school_erp`; no real promotion, exit or target-enrollment records seeded.
- Full regression suite: **242 tests / 1,648 assertions passed** on isolated `school_erp_testing`.
- **26 new promotion tests** cover individual/bulk placement, source preservation, repeat/new section, withdrawal/transfer terminal history, closed-source promotion/read-only exits, canonical retry idempotency and payload conflicts, duplicate processing, mixed outcomes, capacity/roll failures with full rollback, audit rollback for targets and exits, confirmation and malformed/unselected rows, required exit reasons/rolls, chronological/active years, inactive parents/student, later-enrollment conflicts, source/target section ownership, single-student and searchable wizard screens, permission/guest/cross-school access, stale-instance reactivation protection, search/pagination, and database constraints for duplicate sources, student identity and cross-school batch references.
- Production Vite build, Blade compilation, Pint and changed-code whitespace checks passed.
- Chromium checked 11 promotion screens at 320, 390, 768, 1440 and 1920 pixels. Checks covered document overflow, active navigation, bounded page selection, required confirmation, correct single/exit modes, server pagination, batch history and absence of nested forms. Preview fixtures were created within a rolled-back testing-database transaction.

Previews: [batch form](previews/promotions-form-desktop.png), [recorded history](previews/promotions-history-desktop.png), [mobile](previews/promotions-mobile.png).

## Students bulk workflow verification

The Students integration passed **131 related tests / 977 assertions** on isolated `school_erp_testing`. This includes mixed Promote/Repeat destinations, exact confirmation retries, stale reviews, revoked permissions, school boundaries, manual-first automatic roll allocation, capacity and duplicate roll checks, full audit rollback, marks preservation, closed-source eligibility, later-enrollment guards, partial batches, history and old entry redirects. The earlier full-suite figures above describe the original module release.

Chromium verified selection retention across search/pages, explicit confirmation, mixed decisions, retry receipts, remaining-student readiness, missing target-year guidance, history and mobile decision/review cards at 320, 390, 768 and 1440 pixels. Browser fixtures were captured inside a rolled-back testing transaction, and all browser POST requests were mocked. Production build, targeted Pint, Blade compilation and whitespace checks passed. This integration adds no migration and does not process live student records.

Previews: [desktop decisions](previews/student-year-end-decisions-1440.png), [mobile decisions](previews/student-year-end-decisions-390.png), [desktop review](previews/student-year-end-review-1440.png), [mobile review](previews/student-year-end-review-390.png).
