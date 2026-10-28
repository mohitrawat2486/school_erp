# Step 21 — Result Calculation Service (plan Phase 19)

## Delivered

Added a dedicated server-side calculation engine with subject results, weighted totals, exact percentages, configurable grades, pass/fail and cohort ranking. This phase adds service APIs and tests, with no controller, Blade or JavaScript result calculations.

The integrated workflow reads an explicitly selected exam, grading scheme and optional section. It returns a calculation draft only after every active student has complete locked marks for every active exam subject. No result generation record, approval, publication, migration or HTTP screen is introduced here; those belong to the following phases.

## Files

Created:

- `app/Support/ResultMath.php`
- `app/Services/ResultCalculationService.php`
- `tests/Unit/ResultCalculationTest.php`
- `tests/Feature/ResultCalculationServiceTest.php`
- `development/step-21.md`

Modified: `README.md` for the completed phase and next step.

No existing marks, grading configuration, permissions or database schema are changed.

## Service APIs

`ResultCalculationService` exposes:

- `calculateSubjectResult(array $marks, array $configuration): array`
- `calculateTotal(array $subjects): array`
- `calculatePercentage(mixed $obtained, mixed $maximum): string`
- `calculateGrade(GradingScheme $scheme, mixed $percentage): array`
- `calculatePassFail(mixed $obtained, mixed $passing, bool $absent = false): string`
- `calculateRank(array $results): array`
- `calculateExam(User $actor, Exam $exam, GradingScheme $scheme, ?Section $section = null): array`

The component/arithmetic helpers are internal pure calculations over caller-supplied values; they do not retrieve student data or grant authorization. `calculateGrade` uses the existing active, school-scoped GradeResolver. The integrated `calculateExam` is the authorized database entry point for a real exam/roster. Call it from the later generation workflow or queue job rather than duplicating calculations in UI code.

### Subject input and output

Marks input: theory_marks, practical_marks and explicit boolean is_absent. Configuration: theory_max_marks, practical_max_marks and explicit passing_marks. Unused component maxima are null. Configured components require values, including explicit zero for a configured zero maximum. Absence requires all numeric marks to be null.

Subject output includes normalized component marks/maxima, combined max_marks, obtained_marks, percentage, passing_marks, is_absent and PASS/FAIL/ABSENT result_status. Numeric output uses fixed two-decimal strings.

The integrated workflow additionally copies subject identity/name, marks ID/version/remarks and matched grade-rule identity, label, point, percentage bounds and grade remarks. Overall output includes totals, grade values, status, nullable rank, school/year/exam/class/section/student/enrollment identity, name/roll number and grading scheme identity/name/version.

The envelope records exam, scheme/version, CLASS or SECTION rank_scope, optional section_id and the full results array. Output retains enrollment-ID order, while ranking uses scores across the entire cohort.

## Arithmetic and policy decisions

- All arithmetic uses integer hundredths. No binary floating-point sums, percentages or comparisons determine results.
- Component values/maxima are bounded to 0–9999.99. Maximum totals must be positive; values cannot exceed the component maximum. Passing marks must be explicit and within the combined maximum.
- Aggregate percentage is **sum of obtained marks ÷ sum of maximum marks × 100**, rather than the average of subject percentages. Unequal subject weights therefore work correctly.
- Percentages round **half-up to two decimals**, including grade lookup. The percentage service does not silently clamp over-maximum values.
- Pass/fail compares original obtained hundredths against the configured passing marks, never a rounded percentage. A rounded-up displayed percentage cannot convert a failing raw score to PASS.
- Absent subjects contribute zero obtained marks, retain their full maximum in the denominator and have ABSENT status. Absence fails even when passing marks are zero. Overall status is FAIL if any subject is FAIL or ABSENT; otherwise PASS.
- Grade is a percentage classification from the database. It is independent of pass/fail: a student can have a high aggregate grade and still fail a required subject. No grade labels, thresholds or grade-point formulas are hard-coded.
- Ranking includes only overall PASS students. FAIL/absence results have rank null. Competition ties produce 1, 1, 3, using exact obtained marks; rounded percentage ties do not merge distinct scores. Deterministic enrollment IDs break ordering ties without changing the shared rank.
- Ranking requires distinct enrollments and identical total maxima for one cohort. Class and section scopes are explicit; ranks do not reset at pagination/chunk boundaries. These policies are not additionally specified in the plan and are recorded here for review.
- `ResultMath` accepts nonnegative ordinary decimal values with at most two decimal places. Invalid, missing, scientific-notation, negative and over-limit values fail validation. Aggregate arithmetic is capped at 10,000,000,000.00 marks to keep integer multiplication safely within a signed 64-bit integer.

## Database workflow and authorization

`calculateExam` checks school ownership for exam, scheme and optional section, then acquires the same school transaction lock used by marks/configuration mutations. It refreshes the actor and requires both **result.view** and **result.generate**. Marks-review or exam-setup permissions do not grant calculation access.

It refreshes exam/scheme/context and requires an ACTIVE academic year, term/class, active complete grading scheme and exam in MARKS_ENTRY or VERIFICATION. Approved, published, closed and other setup states are rejected. Published results must eventually be read from immutable snapshots, not recomputed through this draft API.

All active exam subjects must pass existing readiness checks. Every active enrollment with an active student in the exam year/class is included, optionally restricted to an active section belonging to that class. An active enrollment in an inactive section is rejected rather than silently skipped. Inactive students/enrollments are excluded and their marks remain untouched. No per-student optional-subject enrollment is introduced; the current roster requires every active configured subject, consistent with marks entry.

Every required marks row must exist, be LOCKED and retain submitted, verified and locked actor/timestamp metadata. Stored component values, totals, subject configuration and enrollment identity are validated again. A missing/unlocked/invalid row aborts the whole draft; no partial cohort is returned. Stale caller models cannot bypass a later unlock or deactivated scheme.

Rules are read once under the same lock, and roster/marks queries run in chunks of 100 with eager context loading. Ranking uses all returned students, independent of query chunking. The service returns a full array, so a future large generation operation should invoke it in a queue and design persistence around cohort size rather than expose a blocking HTTP calculation endpoint.

The service is read-only: no exam transitions, marks edits, audit writes or result records occur. Phase 20 must audit generation/approval actions. Returned scalar grade/configuration values and marks versions are suitable source data for later persisted snapshots, but an in-memory calculation draft is **not** an immutable published result. Phase 21 must persist publication versions and never regenerate historical grades from live rules.

## Verification

- Full regression: **413 tests, 2581 assertions passed**.
- Calculation suites: **54 tests, 144 assertions passed**, including parameterized invalid decimal/rounding cases and database-backed locked-marks integration.
- Covered normal/zero/maximum/decimal marks, theory/practical-only subjects, absence, failed subjects, weighted percentages, grade/pass boundaries, below-threshold scores with rounded-up percentages, invalid input/configuration, incomplete workflow metadata, stale unlocked marks, permission/school isolation, active roster/context, explicit class/section ranking, competition ties, exact-score ranking and 101-student cross-chunk ranking.
- Confirmed calculations do not change marks, exam state or audit counts. Returned grade values retain their original scalar configuration after later scheme revisions, while a new calculation uses the new explicit scheme version.
- Targeted Pint and whitespace checks passed. No frontend files, dependencies or schema changed, so browser/build/migration checks were not needed for this service-only phase.
- Database tests use `school_erp_testing` with isolated fixtures; application marks are not seeded or rewritten.

## How to exercise this phase

Run `php artisan test --filter=ResultCalculation` against the isolated testing database. Tests prepare and roll back representative exam contexts without seeding application marks. The existing application UI remains unchanged; approval/generation screens are the next development phase.

For later application code, call `app(ResultCalculationService::class)->calculateExam($actor, $exam, $scheme, $section)` within an established school context. Inspect the returned draft before persisting it through the authorized generation workflow.

## Next phase

Plan Phase 20 — Result Approval: locked marks → generate draft results → review → approve → publish, with completeness checks and authorized audited transitions. Publication snapshots remain plan Phase 21.
