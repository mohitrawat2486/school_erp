# Step 26 — Class-wise PDF (plan Phase 24)

## Delivered

Individual report cards now offer **Download PDF**. Class-wise Results offers **PDF Selected**, **PDF filtered results** and **PDF Entire Class**. Selected/filtered/class batches are queued and appear under **My PDF Downloads**, with status, student count, captured exam/class label and expiry. Ready requests download one combined PDF in the selected published order.

The PDF renderer reads immutable student/subject snapshots and captured school/identity context. It does not recalculate marks, grades or ranks and does not read current student details or logo files. Individual reports with more than 60 subjects also go through the background flow rather than render synchronously.

## Files

Created:

- `database/migrations/2026_10_28_060000_create_report_pdf_requests.php`
- `config/reports.php`
- `app/Models/ReportPdfRequest.php`
- `app/Services/ReportCardPdf.php`
- `app/Services/ReportPdfService.php`
- `app/Jobs/GenerateReportPdf.php`
- `app/Http/Controllers/ReportPdfController.php`
- `resources/views/report-cards/pdf.blade.php`
- `resources/views/report-cards/pdf-card.blade.php`
- `resources/views/report-pdfs/index.blade.php`
- `resources/views/report-pdfs/show.blade.php`
- `tests/Feature/ReportPdfTest.php`
- `development/step-26.md`

Modified: `composer.json`, `composer.lock`, `config/queue.php`, `app/Providers/AppServiceProvider.php`, `routes/web.php`, `routes/console.php`, `resources/views/report-cards/show.blade.php`, `resources/views/class-results/index.blade.php`, `resources/views/components/app-layout.blade.php`, `README.md`.

## Rendering

Locked dependencies: Dompdf 3.1.6, FPDI 2.6.9 and FPDF 1.9.0, plus Composer-resolved supporting libraries. Composer installation reported no vulnerability advisories. PHP DOM, mbstring, GD and ZIP/zlib capabilities are present locally; Composer verifies package requirements.

A dedicated PDF template uses basic table-based CSS rather than the browser layout's grid. It renders A4 portrait, repeats subject headings over page breaks and reserves labeled manual signature lines. Every student starts on a new page; unusually long reports may span multiple pages.

Dompdf renders chunks of five students (configurable). FPDI imports every generated page and merges the chunks into one PDF. Temporary chunks are private and removed in finally cleanup; parser resources are closed. Captured WebP logos are decoded with GD and embedded as PNG for the PDF renderer. PNG/JPEG data are retained as captured. Remote resources, embedded PHP and PDF JavaScript are disabled.

Implementation references: [Dompdf renderer limitations](https://github.com/dompdf/dompdf/blob/master/README.md), [FPDI document import examples](https://manuals.setasign.com/fpdi-manual/v2/the-fpdi-class/examples/), [FPDI installation requirements](https://manuals.setasign.com/fpdi-manual/v2/installation/). No live remote asset URL or arbitrary user HTML is passed to the renderer.

## Queue and request lifecycle

`report_pdf_requests` records school/creator, captured label, ordered immutable snapshot IDs, student count, QUEUED/PROCESSING/READY/FAILED state, private artifact path, completion and expiry. Composite school/user foreign keys enforce ownership. Output paths/IDs are hidden from model serialization; no editing endpoints exist.

The service refreshes creator permissions and validates every snapshot against the current school and a sealed published batch. It stores the resolved selection, not a query to rerun later. Requests dispatch only after commit, on the dedicated database-backed `result-pdfs` connection/queue. Workers re-establish and clear school context per job and refresh creator access before rendering and before making the artifact ready.

All batch scopes remain consistent with Class-wise Results: selected students must match the filters, filtered includes all matching pages, and Entire Class ignores section/name/result narrowing. Published ranks are retained. One PDF request supports at most 500 students by default; the configurable limit fails explicitly rather than silently truncating. Select a section or smaller group for larger classes. All class batches run asynchronously, even when currently small.

A worker claims a QUEUED row once; repeated delivery of a completed request does not duplicate work. Render/access failures mark FAILED and do not expose exception details or an artifact to the user. Timeout is 300 seconds, retry visibility is 360 seconds and the job has one attempt with failure-on-timeout. The user can request a new batch after resolving failures.

Artifacts remain on the private local disk for 24 hours. Downloads require an active result viewer/printer and the same creator in the same school. Paths are constrained to generated school/UUID PDF paths. Expired downloads return 410, unfinished/invalid artifacts return 409, and unrelated creators cannot inspect/download each other's requests. All responses are private/no-store. Result data itself remains immutable and unchanged.

`results:prune-pdfs` removes expired request metadata/files and old orphan chunks/artifacts from interrupted work. It is scheduled daily; the usual Laravel scheduler must run for cleanup. Expiry checks protect downloads even before cleanup executes.

## Running the worker

For local development:

```bash
composer run pdf:work
```

Equivalent command:

```bash
php -d memory_limit=512M artisan queue:work result-pdfs --queue=result-pdfs --tries=1 --timeout=300 --memory=512
```

`composer run dev` also registers a **PDF Worker** in local mode; restart an already-running dev process to pick up this newly registered process. A plain `php artisan serve` does not itself run queue workers.

For deployment, supervise the same queue worker and run Laravel's scheduler. Restart long-lived workers after deployment using `php artisan queue:restart`. The dedicated connection's retry interval must stay longer than the worker timeout. Rendering chunks reduces memory pressure; merging and the final PDF still consume memory proportional to output size, hence the request bound and explicit worker memory setting.

## Permissions and routes

Every action requires **result.view** and **result.print**. No new permission grants are added. Snapshot ownership/published checks apply to individual PDFs; cohort/selection checks apply to batches. Jobs and downloads recheck current access, so revoked accounts cannot obtain a previously queued file.

- `GET /report-cards/{student_result}/pdf` — individual PDF, or queued flow for an unusually long subject list.
- `GET /report-pdfs` — paginated own requests.
- `POST /report-pdfs` — validated selected/filtered/class request with existing cohort IDs.
- `GET /report-pdfs/{report_pdf_request}` — own status/refresh/download page.
- `GET /report-pdfs/{report_pdf_request}/download` — private ready, unexpired artifact.
- `php artisan results:prune-pdfs` — temporary artifact maintenance.

## Manual checks

1. Open a published report card and choose Download PDF. Confirm a real A4 document with captured branding/identity, component marks, totals, remarks and signature lines.
2. In Class-wise Results, select students and request PDF Selected. Start the worker and refresh My PDF Downloads until ready. Confirm one merged file with the correct students/order, each beginning on a new page.
3. Request PDF Entire Class while a section/search is active; confirm the whole class. PDF filtered results must respect the visible filtering, across all pages.
4. Use a long subject list; confirm continuation pages, repeated table headers and final signature lines without missing subjects.
5. Check captured WebP/PNG/JPEG logos after removing/changing the original file. No live identity/grade changes should rewrite output.
6. Test viewer-only users, another creator/school, revoked accounts, pending/failed requests and expiry. No unauthorized artifact should download.
7. Stop/restart workers and retry status checks. Re-delivery of a ready request must not regenerate it. Inspect failed jobs when a render fails; user pages must not show internal error details.
8. Run prune after expiry and verify file/request removal without altering snapshots. Check mobile request lists and status screens.

## Verification

- Full regression: **498 tests, 3028 assertions passed**.
- PDF suite: **14 tests, 64 assertions** in the final regression, covering actual rendering/merging, dedicated queued requests, captured WebP logos, private ownership, stale permissions, selection validation, failure cleanup, expiry/orphan pruning, pagination and nonduplicate re-delivery.
- Dependencies installed and locked; Composer validation passed. Request-table migration applied. Vite build, Blade compilation, targeted Pint and whitespace checks passed. Daily cleanup appears in `artisan schedule:list`.
- Actual server PDFs checked using PDF page metadata and extracted text: eight-subject individual **1 A4 page**, three-student merged class **3 A4 pages**, and 32-row stress layout **2 A4 pages**. Final subjects, component values and signature labels remain present.
- Six request/control pages at 320, 390, 768, 1440 and 1920 pixels: **30 page/viewport checks passed**, with no document overflow or JavaScript errors. Verified queued/ready/failed/expired controls, 20-row pagination, class PDF buttons and sidebar state.
- Visually reviewed [server-rendered PDF preview](previews/report-card-server.png) and [mobile ready download](previews/pdf-requests-ready-390.png). Samples: [individual PDF](previews/report-card-server.pdf), [combined class PDF](previews/report-card-class.pdf), [desktop downloads](previews/pdf-requests-index-1440.png).
- Preview records/files used isolated testing fixtures and were rolled back/removed. Sample PDFs contain fictional test data. The temporary preview server was stopped; the dedicated local application PDF worker remains running for current-session batch requests. No application sample results were seeded.

## Next phase

Plan Phase 25 — Excel Export: extend published class exports with the specified admission/subject-level columns, formatting and export workflow.
