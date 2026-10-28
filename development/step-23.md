# Step 23 — Result Snapshot (plan Phase 21)

## Delivered

Publication now archives immutable, normalized student and subject results in the same transaction as the existing result workflow. Published batch lists and student pages read those snapshot tables. Live marks, names and grading rules are not recalculated or consulted to reconstruct historical results.

New student archive URLs are `/published-results/{student_result}`. Existing `/results/{batch}/students/{draft}` links remain compatible and render the corresponding snapshot after publication. Generated/approved/returned revisions continue to show their saved review payloads.

## Files

Created:

- `database/migrations/2026_10_28_040000_create_published_result_snapshots.php`
- `app/Models/StudentResult.php`
- `app/Models/StudentResultSubject.php`
- `app/Policies/StudentResultPolicy.php`
- `app/Services/ResultSnapshotService.php`
- `app/Services/PublishedResultReader.php`
- `resources/views/results/snapshot.blade.php`
- `tests/Feature/ResultSnapshotTest.php`
- `development/step-23.md`

Modified:

- `app/Services/ResultApprovalService.php`: archive before publication transaction commits.
- `app/Models/ResultBatch.php`: completion marker cast and student snapshot relationship.
- `app/Http/Controllers/ResultApprovalController.php`: published reads use normalized snapshots; review reads remain unchanged.
- `resources/views/results/show.blade.php`: published student rows link to the archive.
- `routes/web.php`: read-only snapshot route within existing authentication/school/result permission middleware.
- `routes/console.php`: authorized historical archival command.
- `README.md`: completed phase and next step.

## Schema

`student_results` contains every planned field: school/year/exam/student/enrollment, total maximum, obtained marks, percentage, grade, nullable rank/remarks, result status, published_at, version and created_at. There is no updated_at.

Additional copied values preserve original student, exam, academic-year, class, section and grading-scheme names; roll number; class/section identity; grading scheme version; grade point, range and remarks. Batch/source-draft identity and source payload hash retain provenance. Composite foreign keys enforce school, exam, enrollment and section context. Uniqueness covers school/exam/student/version, school/batch/enrollment and source draft.

`student_result_subjects` stores the required parent, subject identity/name, maximum/obtained marks, grade and status. It also copies theory/practical marks and maxima, passing marks, percentage, absence, points/range/grade remarks, marks identity/version, original grade rule identity and remarks. It has created_at and no updated_at. Subject/rule/marks source IDs are copied provenance rather than live display dependencies; the frozen names and numeric values remain authoritative.

Snapshots use fixed decimal columns. Total marks support the calculation service's aggregate bound; subject columns support combined theory/practical maximums. Checks enforce valid totals, percentages, statuses, positive versions/ranks and absence consistency. Foreign keys are restrictive and no snapshot delete endpoints exist.

`result_batches.snapshots_completed_at` seals a completed archive. Version uses the published batch revision, including a revision greater than 1 after pre-publication corrections. Original publication time is preserved; created_at records when normalization occurred, which may be later for historical backfill. This phase does not introduce amendments to already published results.

## Immutability and transaction behavior

Eloquent models reject snapshot updates/deletes. MySQL triggers additionally reject raw SQL/bulk updates and deletes of both snapshot tables. Parent inserts require an unsealed PUBLISHED batch with matching exam/year/class/scheme/revision/publication time and the correct saved draft/student/enrollment. Subject inserts require an unsealed published parent archive.

After all students/subjects are inserted, the batch is sealed. Database triggers then prevent later snapshot additions, changes to the sealed batch, and inserts/updates/deletes of its original draft payloads. Existing source marks/configuration may subsequently change through their own authorized workflows without rewriting archived values. Database administrators with schema-altering privileges can still remove database protections; the application exposes no such operation.

`ResultSnapshotService::archive($actor, $batch)` refreshes ownership and active actor permissions under the school transaction lock. It requires `result.view` and `result.publish`, a fully published batch, a nonempty valid saved payload hash and matching identities. It copies approved scalar values; it never calls ResultCalculationService, GradeResolver or live student/configuration records to reconstruct values.

Publication calls this service after setting the batch/exam publication metadata but before committing. Archive or audit failure rolls back snapshot rows, seal, publication actors/times, batch version and exam status together. Archival records `RESULT_SNAPSHOTS_ARCHIVED` in addition to existing publication audits.

A repeated archive of a completed batch returns zero without duplicate rows/audits. An existing partial unsealed archive fails for investigation rather than overwriting immutable data. Supported archival writes are atomic, so a normal failure leaves no partial archive and can be retried.

## Historical backfill

The migration changes schema and guards only. Existing published Phase 20 batches can be normalized from their retained payloads, including after marks/names/grades or academic-year status change:

```bash
php artisan results:archive-published SCHOOL authorized-operator@example.com
php artisan results:archive-published SCHOOL authorized-operator@example.com --batch=123
```

Use an actual active school code and authorized operator account. The command independently checks school membership and `result.view`/`result.publish`, scopes batch selection to that school and processes batches transactionally. Re-running is safe. It reports created student counts and failed batches and returns nonzero on failure. No accounts or permissions are granted.

Backfill preserves each original published_at/revision and copies original approved payload values. It verifies the saved payload hash without checking current live source hashes; historical source changes are expected and must not cause recalculation. Invalid/tampered historical payloads fail safely.

An unarchived legacy publication returns HTTP 409 until archival completes. There is no silent fallback to current configuration or the old draft reader. This makes archival status explicit rather than mixing historical read paths.

## Reading and access

Reading requires only `result.view`, same-school ownership and a published batch. It does not require publishing rights, an active academic year, a live grading scheme or a current teacher assignment. School/account activity still follows existing middleware rules. Cross-school snapshot IDs return 404; unauthorized users receive 403 and guests go to login.

Published batch student lists and subject lists paginate at 20 rows and retain full-width layouts/mobile table scrolling. Snapshot pages show the original publication date and version. A closed exam/year remains readable. No snapshot editing, approval or deletion routes are added.

## Manual checks

1. Generate and approve a class result with complete locked marks. Publish and verify one student_results row and its subject rows per saved student; batch should have a completion marker.
2. Open Results → Result Approval → published batch → student Details. Confirm saved totals/grades, original subject names, publication date and version. Verify the previous draft-based URL shows the same snapshot.
3. Change live names/section/grades after publication; archived names, values, marks and grade points must stay unchanged.
4. Close the exam and academic year; published snapshots remain readable to result viewers.
5. Try snapshot model changes, bulk SQL updates/deletes or extra inserts after sealing. All must fail without changing data. Sealed original payloads/batch must also reject SQL mutation.
6. Check zero/null points, absence, FAIL with null rank, decimal marks and revision 2 after a returned generation.
7. With more than 20 students/subjects, verify pagination and phone table scrolling. Check read-only users, guests and another school's IDs.
8. For a legacy published batch, run the archival command twice. Confirm the second creates zero rows and preserves original publication time, even after live configuration changes.
9. Tampered payloads or missing permission must reject archival. Audit failure must roll back a new publication or backfill completely, permitting a safe retry.

## Verification

- Full regression: **458 tests, 2817 assertions passed**.
- Snapshot and approval suites: **45 tests, 236 assertions passed**, including **20 new snapshot tests** covering normalized values, immutable models/bulk SQL, sealed appends/payloads, atomic publication rollback, historical backfill/idempotency, source-independent reads, closure, versioning, permissions, school isolation and pagination.
- Snapshot migration applied successfully to `school_erp`. Checked existing publications: **0 batches awaiting archival**, so no application results required backfill. Backfill behavior is verified with isolated legacy fixtures.
- Vite build, Blade compilation, targeted Pint and whitespace checks passed.
- Seven rendered pages at 320, 390, 768, 1440 and 1920 pixels: **35 page/viewport checks passed**, with no document overflow or JavaScript errors. Verified saved values, old URL compatibility, no editable result forms, closed-year reads, live-data changes and pagination.
- Visually reviewed [mobile student snapshot](previews/result-snapshot-student-390.png) and [desktop student snapshot](previews/result-snapshot-student-1440.png). Also saved [published batch](previews/result-snapshot-batch-1440.png).
- Preview fixtures used only `school_erp_testing` inside a rolled-back transaction. Static previews exercise rendering; feature/service tests exercise mutations and protections. Temporary preview server stopped after verification. No application demo marks/results were seeded.

## Next phase

Plan Phase 22 — Class-wise Results: filters for academic year, exam, class and section, with student totals, percentages, grades, ranks and result statuses read from published snapshots.
