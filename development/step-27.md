# Step 27 — Excel Export (plan Phase 25)

Open **Results → Class-wise Results**, choose a published year/exam/class, apply optional section, name and status filters, then click **Export Excel · Subject marks (.xlsx)**. Every matching student across pagination is included, in the same order as the directory.

## Workbook

- **Class Results:** roll number, admission number, student name, section, one marks column per subject, total, maximum, percentage, grade, grade point, result, original class rank, year, exam, class, revision and publication time.
- **Subject Results:** one row per student/subject, including theory/practical components and maxima, obtained/maximum marks, percentage, passing marks, grade/point, result, absence and saved remarks.
- **Export Notes:** cohort and interpretation guidance.

The worksheets have styled, wrapped headings, frozen headings/identity columns and filters. Numeric marks remain numeric; percentages use a literal percent suffix because stored percentages already range from 0 to 100. Admission numbers remain text, including leading zeros. All names and remarks are explicit spreadsheet text, so formula-like values cannot execute as formulas. XML control characters are removed.

Data comes exclusively from immutable published student and subject snapshots. No calculation runs and no source data is changed. Legacy admission numbers absent from publication are marked `Not recorded at publication`; current student records are never substituted. Absence displays `ABSENT` in the summary and `Yes` in details, distinct from zero marks. Subject columns use stable subject-ID order; repeated copied names receive numeric suffixes.

## Implementation and limits

`app/Services/ClassResultWorkbook.php` extends the existing native XLSX writer. Laravel Excel is not installed, so no package was added. XMLWriter streams worksheets to private temporary parts; subjects load in chunks of 100 students. ZIP parts and output are cleaned on errors; successful HTTP downloads delete the temporary workbook after sending.

`config/exports.php` caps exports at 100,000 subject detail rows and 500 distinct subjects. Existing directory validation caps exports at 5,000 students and rejects incomplete, empty or mismatched contexts. Exceeding limits produces a validation error without a truncated workbook. Access remains school-scoped and requires both `result.view` and `result.print`. No schema, migration, grants or dependency changes are needed.

Changed files: workbook service, export configuration, class-results view, class-result feature tests and README. The existing export route/controller remain compatible.

## Verification

Feature tests cover all pages, publication-only scope, school boundaries, permissions, preserved ranks, read-only operation, valid workbook XML, formula-safe text, captured/legacy identity, zero/absence distinction, styles and export limits. Full regression: 501 tests / 3,052 assertions passed. After adding absence coverage, the final class-result suite passed all 18 tests / 120 assertions. Vite production build, compiled Blade views, Pint and whitespace checks pass.

[Sample workbook](previews/class-results-detailed.xlsx) uses fictitious data: eight subjects and admission `000148`. It was generated inside an isolated testing-database transaction and rolled back. ZIP integrity and every XML part were checked, including the expected detail-row count and exact admission text. A desktop Excel/LibreOffice visual check was not available in this environment.

Next: plan Phase 26 — Audit Logging.
