# Step 25 — Report Card (plan Phase 23)

## Delivered

Open an immutable published student result and choose **Print report card**, or use the individual Print action in Class-wise Results. The dedicated browser print page contains:

- Original school name/address and captured logo when configured.
- Academic year, exam, student name, admission number, class/section, roll and date of birth.
- Published subject theory/practical marks and maxima, totals, grades and result status.
- Overall maximum/obtained marks, percentage, grade, result and class rank.
- Saved teacher remarks and labeled spaces for Class Teacher and Principal signatures.
- Original publication date and version.

The A4 portrait layout uses dedicated print CSS without Tailwind/Vite in the report template. Browser controls disappear in print media; table headers repeat, rows avoid splitting and long reports continue to additional pages. The screen layout adapts to phones with a horizontally scrolling subject table. Signature lines are blank for authorized manual signing; no signatures or approver names are invented.

## Files

Created:

- `database/migrations/2026_10_28_050000_add_report_identity_to_snapshots.php`
- `app/Services/ReportCardContext.php`
- `app/Http/Controllers/ReportCardController.php`
- `resources/views/report-cards/show.blade.php`
- `tests/Feature/ReportCardTest.php`
- `development/step-25.md`

Modified:

- `app/Services/ResultCalculationService.php`: copy admission number/date of birth into calculation output.
- `app/Services/ResultApprovalService.php`: capture school report context once per generated batch; audit workflow fields/hashes without duplicating embedded logo bytes.
- `app/Services/ResultSourceFingerprint.php`: include report branding and date of birth in pre-publication freshness checks.
- `app/Services/ResultSnapshotService.php`: archive captured student report identity.
- `app/Models/StudentResult.php`: cast nullable report_context JSON.
- `app/Models/ResultBatch.php`: explicit workflow audit field list.
- `routes/web.php`: report-card route.
- `resources/views/results/snapshot.blade.php`: report-card action for print-authorized viewers.
- `resources/views/class-results/index.blade.php`: individual Print now opens the full report card; selected/class summaries remain available.
- `README.md`.

## Historical accuracy

Earlier snapshot versions did not contain admission number, date of birth or school branding. This migration adds **nullable report_context** to student_results without updating immutable existing records. New generations include student admission/date of birth and a batch-level report_school context. New publications copy student identity into normalized snapshots. School name/address/logo remain in the sealed batch context, stored once rather than duplicated for every student.

Report rendering reads only the published snapshot, its normalized subject rows and sealed batch report context. It never retrieves current student identity, school profile/logo, grades or marks to reconstruct a report. Later profile changes or deletion of the original logo file do not change an archived report.

For older publications that did not capture these extra fields, missing identity/address is explicitly **Not recorded at publication**; missing branding uses a neutral school heading/emblem placeholder. Current data is not silently inserted into historical output. Existing published records are not rewritten or backfilled with unverifiable current identity.

Pre-existing generated/approved batches may need return/regeneration before publication because the source fingerprint now includes report identity/branding. Already published records remain readable and retain their original values. Historical backfill continues to copy saved values only.

The school logo is captured as an embedded data URI from the existing private raster upload. PNG, JPEG and WebP up to 2 MB are supported, consistent with school upload formats. Configured missing/invalid logo files cause a generation/freshness error rather than silently archiving a broken image. No remote logo URL is fetched; the renderer accepts only the captured raster data-URI types.

Teacher remarks come from immutable overall/subject remarks. Empty remarks show **No remarks recorded**. Signature lines do not claim that the report was digitally signed.

## Access and route

`GET /report-cards/{student_result}` requires an active authenticated school account, same-school published snapshot ownership, **result.view** and **result.print**. Direct URLs enforce the same rules as visible actions. Guests go to login, unauthorized accounts receive 403 and cross-school IDs return 404. Closed years/exams remain printable because the read contract is historical.

Responses are private/no-store. All text is Blade-escaped. No calculation, snapshot mutation, permission grant or new PDF dependency is introduced. Browser printing is explicit; the page does not open a print dialog automatically.

## Manual checks

1. Configure school name/address/logo before generation and confirm student admission number/date of birth. Generate, approve and publish complete locked marks.
2. Open the student snapshot → Print report card. Check original school/student identity, every theory/practical value, totals, percentage, grade, status and remarks.
3. Print to A4 portrait. Verify controls disappear, normal reports fit appropriately, long tables continue with repeated headers and final signatures remain present.
4. Check an absent/failed student, theory-only and practical-only subjects, optional remarks and null rank. Unused components should display a dash rather than fabricated marks.
5. Change live names, DOB, admission number, branding or logo file after publication. The report must retain captured values and embedded logo.
6. Change DOB/branding during review; approval must require return/regeneration. Missing configured logo files should produce a clear generation error.
7. An older publication without new metadata must show missing-at-publication labels rather than live identity values.
8. Verify view-only users cannot print and direct cross-school IDs/guest access are denied. Close the year and verify the archived report still prints.
9. Check phone layout and long text/subject names. Server-side PDF and selected/class report-card PDF output remain the following phase.

## Verification

- Full regression: **484 tests, 2964 assertions passed**.
- Report-card suite: **12 tests, 53 assertions passed**, covering captured branding/logo/identity, exact component-mark display, stale DOB/branding, legacy missing fields, permissions, school isolation, closure, absence, escaped text and immutable data.
- Nullable report-identity migration applied successfully. Vite build, Blade compilation, targeted Pint and whitespace checks passed.
- Three report variants at 320, 390, 768, 1440 and 1920 pixels: **15 page/viewport checks passed**, with no document overflow or JavaScript errors. Verified logo loading, original identity, missing-field labels, component values and print-media controls.
- Native browser print verification: the eight-subject example produces **one A4 portrait page**; the 32-row layout stress variant produces **two A4 pages**. PDF page metadata/text checks confirm hidden controls, retained last subjects and signature labels. These PDFs were temporary verification artifacts, not a new server PDF feature.
- Visually reviewed [desktop report card](previews/report-card-1440.png) and [mobile report card](previews/report-card-390.png). Visual review caught and corrected a Blade conditional issue in component-mark cells; explicit numeric assertions now cover it.
- Preview data used an isolated testing transaction and was rolled back. Legacy/long variants were read-only presentation copies for layout checks. The temporary preview logo was removed and local preview server stopped. No application sample results were seeded.

## Next phase

Plan Phase 24 — Class-wise PDF: individual, selected and entire-class report-card PDF output, with architecture suitable for queued heavy batches.
