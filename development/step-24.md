# Step 24 — Class-wise Results (plan Phase 22)

## Delivered

Open **Results → Class-wise Results**. Choose academic year, exam, class and optionally a section. Pickers use historical names from sealed published snapshots and paginate at 20 records; they remain usable after live records change or the year closes.

The full-width results list shows roll/section, student, obtained/maximum marks, percentage, grade, PASS/FAIL and original published class rank. Search student names or filter PASS/FAIL. Filters remain in pagination links; search resets without losing the selected cohort. Changing context restarts the scoped selection flow.

Actions:

- **View** opens the immutable student snapshot.
- **Print** opens a printable summary for one student.
- **Print Selected** uses checked students on the current page, including a select-all checkbox.
- **Print filtered results** includes every matching row across pages.
- **Print Entire Class** includes every section and ignores student-name/result filters. It does not recalculate ranks.
- **Export Excel (.xlsx)** downloads every matching row across pages as a real workbook.

Printing produces a browser-printable A4 landscape class summary. It does not replace plan Phase 23's individual A4 report card or Phase 24's PDF output. This phase's Excel export covers the published summary; later export work can add subject-level sheets and queued large exports.

## Files

Created:

- `app/Services/ClassResultDirectory.php`
- `app/Services/ClassResultWorkbook.php`
- `app/Http/Controllers/ClassResultController.php`
- `resources/views/class-results/select.blade.php`
- `resources/views/class-results/index.blade.php`
- `resources/views/class-results/print.blade.php`
- `tests/Feature/ClassResultTest.php`
- `development/step-24.md`

Modified: `routes/web.php`, `resources/views/components/app-layout.blade.php`, `resources/js/app.js`, `README.md`.

No schema, snapshot values, permissions or role grants change. PHP's ZIP extension is required for workbook output and is present in the local environment. No external packages were added.

## Query and access rules

All queries start from school-scoped `StudentResult` rows whose parent batch is PUBLISHED and sealed. Year/exam/class/section IDs must match the earlier selected published context; out-of-order or mismatched IDs fail validation. Context choices derive from snapshot IDs/names, not current master records or active-year filters.

The year, exam and class are required for list/output requests; section is optional. Class ranks remain exactly as published when a section, search or result-status filter narrows the list. No results or grades are recomputed. This works with the current single terminal publication per exam; any future amendment workflow must explicitly define version selection rather than mixing published versions.

Reading requires `result.view`. Print and Excel actions additionally require the existing **result.print** permission. No implicit generation/approval rights are granted and no new export permission is introduced. Guests and users without result access are denied by existing authenticated school middleware.

Selected IDs must be distinct, belong to the current published filters and include at least one student. Empty, cross-section, unknown and cross-school IDs are rejected rather than silently dropped. The endpoint accepts at most 1000 selected IDs; normal UI selection is limited to the visible page. No selection persists secretly across pagination.

Synchronous outputs are capped at 5000 students and fail rather than truncate. Use a filtered section/selection for a smaller output. Large-cohort queue/PDF/export architecture remains a later expansion.

## Print and workbook behavior

The print page shows saved exam/year/class context, original publication version/date, all requested students and preserved marks/grades/ranks. A user explicitly opens the browser print dialog; there is no automatic print pop-up. Toolbar controls disappear in print media; table headers repeat and rows avoid page breaks. On phones the on-screen table scrolls horizontally.

The workbook uses native ZIP/SpreadsheetML with workbook, worksheet, relationships and content types. This follows Microsoft's [SpreadsheetML document structure](https://learn.microsoft.com/en-us/office/open-xml/spreadsheet/structure-of-a-spreadsheetml-document). Numeric totals, percentages, points, ranks and versions are numeric cells. Names, grades and other text are explicit inline strings, so values beginning with `=` cannot become spreadsheet formulas. XML text is escaped and invalid XML control characters are removed.

The summary includes academic year, exam, class, section, roll, student, obtained/maximum, percentage, grade/point, result, rank, publication version and date. Zero remains numeric; null points/ranks remain blank. Export uses a private temporary file, a fixed download filename and removal after sending. Print/download responses use private no-store cache headers.

The list and all output paths are read-only; no marks, snapshots, ranking, approval metadata or audit history are rewritten.

## Routes

- `GET /class-results/select` — paginated guided choices; `search` filters the current choice list.
- `GET /class-results` — required `academic_year_id`, `exam_id`, `class_id`; optional `section_id`, `q`, `result_status`, `page`.
- `GET|POST /class-results/print` — the same cohort plus `scope=selected|filtered|class`, and `ids[]` for selection. POST uses normal CSRF protection.
- `GET /class-results/export` — Excel workbook for the current filtered cohort.

## Manual checks

1. Open Class-wise Results with `result.view`. Choose year → exam → class → section or all sections. Confirm copied historical names and saved totals.
2. Search a student and filter PASS/FAIL. Reset search and verify the cohort remains selected. Page through more than 20 students and verify filter links.
3. Select all on the current page, uncheck one and check the indeterminate state. Print selected; only selected students should appear.
4. Print one row, print filtered results across pages and print the entire class while a section/search is active. Entire class must include all sections; ranks must not reset for subsets.
5. Download the Excel file. Open it in an XLSX-compatible spreadsheet application; check numeric marks, text names/grades, blank ranks/points and all matching rows beyond page 1.
6. Try empty/duplicate/outside-cohort selections and mismatched year/exam/class/section IDs. Reject safely without output or changes.
7. A view-only user can see records but cannot print/export. Check guests, unrelated school users and direct output URLs.
8. Close the year or change live names/configuration; historical results remain available and unchanged. Verify snapshot View links.
9. Check mobile list scrolling, sidebar collapse and print media behavior. No application sample data is seeded.

## Verification

- Full regression: **472 tests, 2911 assertions passed**.
- Class result suite: **14 tests, 94 assertions passed**. Covered guided/historical filters, preserved ranks, closure/live-name changes, permission/school isolation, pagination, selection validation, full outputs across pages, XLSX package/XML/numeric values, safe string cells and no snapshot mutation.
- Vite build, Blade compilation, targeted Pint and whitespace checks passed. No migration was required.
- Eight rendered pages at 320, 390, 768, 1440 and 1920 pixels: **40 page/viewport checks passed**, with no document overflow or JavaScript errors. Verified filter steps, select-all/indeterminate behavior, disabled empty selection, paginated tables and print-media toolbar hiding.
- Visually reviewed [mobile directory](previews/class-results-index-390.png) and [print summary](previews/class-results-print-1440.png). Also saved [desktop directory](previews/class-results-index-1440.png).
- Preview fixtures ran in a rolled-back transaction in `school_erp_testing`. Static previews exercise rendering/client behavior; feature tests exercise printing/downloads and authorization. Temporary preview server stopped after verification. No application demo results were seeded.

## Next phase

Plan Phase 23 — Report Card: a printable individual A4 report card using published snapshot values, with the required school/student/subject/result information.
