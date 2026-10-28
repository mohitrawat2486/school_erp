# Step 15 — Student Promotion (plan Phase 13)

## Completed

Individual and bulk promotion/repeat workflows, explicit target year/class/section and per-student roll assignments, withdrawal/transfer exits, searchable retained history and batch receipts. Enrollment details link to individual processing and recorded source/origin outcomes. No examination module is implemented in this phase.

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

**Students → Promotion** lists/searches history by student, admission number or decision. Promote/repeat and withdrawal/transfer start separate workflows. Searchable paginated pickers choose the source context and, for placement, target context. Enrollment details offer a single-student shortcut.

The final screen displays context, eligible students, checkboxes, explicit decisions, new roll numbers and notes. Only checked rows are submitted. One joined date and destination section apply to the batch; run separate batches for different destinations/dates. The user must confirm the reviewed selections. Validation errors retain selected row values when returning to the same page.

The UI processes up to 20 selected students on the current page. Search/page changes clear unsaved choices, as explained on screen. The service caps a request at 100 rows; larger cohorts should use multiple batches. No queue or new dependency is needed for these bounded operations.

## Routes

- `GET /promotions` — retained outcome history and search
- `GET /promotions/create` — selection and placement form
- `GET /promotions/create?mode=EXIT` — withdrawal/transfer selection
- `GET /promotions/create?source_enrollment_id={id}` — individual placement shortcut; add `mode=EXIT` for an individual exit
- `POST /promotions` — confirmed atomic processing
- `GET /promotions/{promotion}` — batch receipt with paginated outcomes

## Manual testing

1. Prepare an ACTIVE or CLOSED source year, source enrollments, and an ACTIVE target year beginning after the source year ends. Activate target classes/sections and ensure available capacity.
2. Sign in with `student.view` and `student.update`. Open **Students → Promotion → Promote / repeat**. Search/select source year/class/section and target year/class/section.
3. Check individual students, choose Promote, enter distinct positive roll numbers and target joined date, review the context and check confirmation. Submit and review the batch receipt.
4. Verify each student has a new target-year enrollment and unchanged source placement. Open either enrollment to follow its origin/outcome link. Retrying the same submission should return the original batch; a new operation cannot reprocess the same source.
5. For a repeater, select the same class in a later year (a different section is allowed), select Repeat, enter the new roll and confirm.
6. For an individual, open enrollment details → **Promote / repeat student** or **Withdraw / transfer student**.
7. For withdrawal/transfer, use an ACTIVE source year, check students, select the appropriate exit decision, supply reasons and confirm. Verify retained source statuses and no new enrollments. Ordinary edit/reactivation should be unavailable.
8. Try a duplicate target roll, full section, inactive target/student, pre-existing later enrollment, invalid joined date or duplicate source. Verify no partial batch is saved.
9. Verify a view-only user can read history but cannot process; foreign-school IDs must remain inaccessible. Check corresponding audit events using `audit.view`.
10. Check phone table scrolling, picker/history pagination and validation feedback. Searching/changing the source page should clear unsaved selection.

## Next recommended phase

Examination Module (plan Phase 14): school/year exam terms and class-specific examinations, statuses, authorization and validation.

## Verification

- Migration applied to `school_erp`; no real promotion, exit or target-enrollment records seeded.
- Full regression suite: **242 tests / 1,648 assertions passed** on isolated `school_erp_testing`.
- **26 new promotion tests** cover individual/bulk placement, source preservation, repeat/new section, withdrawal/transfer terminal history, closed-source promotion/read-only exits, canonical retry idempotency and payload conflicts, duplicate processing, mixed outcomes, capacity/roll failures with full rollback, audit rollback for targets and exits, confirmation and malformed/unselected rows, required exit reasons/rolls, chronological/active years, inactive parents/student, later-enrollment conflicts, source/target section ownership, single-student and searchable wizard screens, permission/guest/cross-school access, stale-instance reactivation protection, search/pagination, and database constraints for duplicate sources, student identity and cross-school batch references.
- Production Vite build, Blade compilation, Pint and changed-code whitespace checks passed.
- Chromium checked 11 promotion screens at 320, 390, 768, 1440 and 1920 pixels. Checks covered document overflow, active navigation, bounded page selection, required confirmation, correct single/exit modes, server pagination, batch history and absence of nested forms. Preview fixtures were created within a rolled-back testing-database transaction.

Previews: [batch form](previews/promotions-form-desktop.png), [recorded history](previews/promotions-history-desktop.png), [mobile](previews/promotions-mobile.png).
