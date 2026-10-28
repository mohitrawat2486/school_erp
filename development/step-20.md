# Step 20 — Grading (plan Phase 18)

## Delivered

Open **Examinations → Grading Schemes**. Create an inactive scheme, add its percentage ranges, then activate it after completing all rules. Names and grade labels are school/scheme-specific; no grade labels, points, ranges or demo data are seeded or hard-coded.

Each rule defines minimum/maximum percentage, grade label, optional grade point and optional remarks. Active schemes show a server-side **Check a percentage** lookup. Inactive schemes allow renaming and rule creation/edit/removal; active configuration is read-only until deactivated. The directory and nested rules list use the full workspace width and paginate at 20 rows. Forms retain readable widths and the shared collapsible/mobile sidebar.

## Files

Created:

- `database/migrations/2026_10_28_020000_create_grading_schemes.php`
- `app/Enums/GradingSchemeStatus.php`
- `app/Models/GradingScheme.php`
- `app/Models/GradeRule.php`
- `app/Policies/GradingSchemePolicy.php`
- `app/Services/GradingSchemeService.php`
- `app/Services/GradeResolver.php`
- `app/Http/Controllers/GradingSchemeController.php`
- `resources/views/grading/index.blade.php`
- `resources/views/grading/show.blade.php`
- `resources/views/grading/form.blade.php`
- `resources/views/grading/rule-form.blade.php`
- `tests/Feature/GradingTest.php`
- `development/step-20.md`

Modified: `routes/web.php`, `resources/views/components/app-layout.blade.php`, `README.md`.

## Database and configuration

`grading_schemes`: id, school_id, unique school-scoped name, ACTIVE/INACTIVE status, lock_version and timestamps. New schemes default to INACTIVE.

`grade_rules`: id, school_id, grading_scheme_id, min_percentage, max_percentage, grade, nullable grade_point, nullable remarks and timestamps. Composite school/scheme foreign keys prevent cross-school ownership and restrict scheme deletion. Grade labels are unique within a scheme. Percentage/range and nonnegative point checks also run in MySQL.

Percentage endpoints use DECIMAL(5,2), range 0.00–100.00, with minimum ≤ maximum. Grade labels are trimmed, required and at most 20 characters; names are trimmed and at most 100 characters. Optional points use DECIMAL(5,2), 0–999.99; explicit zero remains distinct from null. Remarks are at most 500 characters and escaped in views.

Rules cannot overlap, including a shared endpoint. Inactive schemes may be incomplete while configured. Activation requires exact continuous coverage from 0.00 through 100.00 using integer hundredths; there must be no gaps or overlaps.

Both endpoints are **inclusive at two decimal places**. For example: 0.00–49.99 followed by 50.00–100.00. The plan's integer-only example 0–32 and 33–40 leaves a decimal gap; use 0.00–32.00 and 32.01–40.00, or another explicitly chosen continuous boundary. The app rejects gaps instead of silently rounding or inventing grade thresholds. A single-value rule such as 0.00–0.00 is valid if the remaining rules complete coverage.

## Services and permissions

Existing `exam.view` permits reading and lookup. Creating, editing, managing rules and activation additionally require `exam.update`. `exam.create` alone does not grant grading administration. No permissions or role grants are added automatically.

`GradingSchemeService` refreshes actor and scheme under the existing school transaction lock, rechecks permission and expected version, validates ranges/duplicates and audits every effective change. Every rule mutation increments its parent scheme version. Stale forms fail; validation redirects retain the submitted version. No-op saves/status requests do not increment versions or duplicate audits. School, scheme identity and status cannot be supplied through editing payloads.

Rule removal uses an explicit confirmation and is available only while inactive. Removed rule values remain in the parent scheme's audit event. Schemes are retained by deactivation; no scheme deletion endpoint exists. Audit failure rolls back data, status and version. Future references to rules must restrict deletion or use immutable snapshots before adopting them for historical results.

`GradeResolver::resolve($scheme, $percentage)` validates a percentage with at most two decimals, refreshes active scheme status and reads the matching database rule under the same school lock, preventing lookup/edit races. It refuses missing/ambiguous configuration rather than choosing a fallback grade. It returns the matching `GradeRule`, including configured point/remarks. This is an internal school-scoped service; HTTP lookup first passes the scheme policy. No calculation or lookup logic is placed in Blade or JavaScript.

There is no global default scheme, exam binding, result generation or percentage-rounding policy in this phase. The caller explicitly supplies the scheme and normalized percentage. Phase 19 must define result rounding and persist the selected scheme/rule values or immutable version snapshots so later configuration changes do not rewrite historical grades. Current active freezing prevents accidental edits during configuration use; it does not claim to version future result records.

## Routes

- `GET /examinations/grading-schemes` — paginated name/status directory.
- `GET /examinations/grading-schemes/create`, `POST /examinations/grading-schemes`.
- `GET /examinations/grading-schemes/{grading_scheme}` — details/rules; optional `percentage` for active lookup.
- `GET .../{grading_scheme}/edit`, `PUT .../{grading_scheme}`.
- `POST .../{grading_scheme}/activate`, `/deactivate` — expected `version`.
- `GET .../{grading_scheme}/rules/create`, `POST .../{grading_scheme}/rules`.
- `GET .../{grading_scheme}/rules/{grade_rule}/edit`, `PUT` and `DELETE .../{grading_scheme}/rules/{grade_rule}`.

Rule mutations require the parent's expected `version`. Nested rules cannot be edited or moved through another scheme. School scope protects route binding; cross-school IDs return 404 and direct services independently check ownership.

## Manual testing

1. Sign in with `exam.view` and `exam.update`. Add a named scheme; verify INACTIVE status and empty rules.
2. Add 0.00–49.99 → a chosen grade, then 50.00–100.00 → another grade with optional point/remarks. Activate.
3. Check 0, 49.99, 50, 99.99 and 100 through the lookup. Returned labels/points must match the saved database configuration exactly.
4. Try overlapping endpoints, duplicate labels (including case variants), negative/over-100 percentages, reversed bounds, three decimals, missing labels, invalid points and overlong remarks. Reject without saving.
5. Try activating empty/incomplete ranges or 0–32 followed by 33–100. Require complete decimal coverage. Correct the gap and activate.
6. Active schemes must hide edit/removal controls and reject direct mutations. Deactivate, edit rules and reactivate. Inactive lookup must fail.
7. Open configuration in two tabs; mutate one, then submit the other. Stale versions must fail. Reload before saving again.
8. Verify read-only and exam-create-only users cannot administer schemes. Try cross-school IDs and another scheme's nested rule ID.
9. Verify creation/edit/status/removal audits, audit rollback and no-op behavior. Check explicit zero and blank grade points remain distinct.
10. With more than 20 schemes/rules, verify pagination/filter links and mobile table scrolling. No existing marks or results should be altered.

## Verification

- Full regression: **359 tests, 2437 assertions passed**.
- Grading feature suite: **21 tests, 134 assertions passed**, covering configuration, exact boundaries, decimal coverage, overlaps, invalid data, zero/null points, permissions, school isolation, stale versions, audit rollback, no-op behavior, pagination, lookup freshness and ambiguous/missing rules.
- Migration applied successfully to the local application database. Vite build, Blade compilation, targeted Pint and whitespace checks passed.
- Seven rendered pages checked at 320, 390, 768, 1440 and 1920 pixels: **35 page/viewport checks passed**, with no document overflow or JavaScript errors. Verified pagination, active lookup values, frozen controls, required fields, numeric bounds and one active sidebar link.
- Visually reviewed [mobile rule form](previews/grading-rule-form-390.png) and [desktop active lookup](previews/grading-active-1440.png). Also saved [desktop directory](previews/grading-index-1440.png) and [desktop rule form](previews/grading-rule-form-1440.png).
- Browser fixtures used only the isolated testing database inside a rolled-back transaction. Static previews test rendering; feature/service tests cover mutations and access control. Temporary preview server stopped after verification.

## Next phase

Plan Phase 19 — Result Calculation Service: server-side subject/total/percentage/grade/pass-fail/rank calculation with decimal and boundary tests, consuming explicit grading configuration.
