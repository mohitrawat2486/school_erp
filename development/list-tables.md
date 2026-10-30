# Search and pagination across list pages

Read-only data lists now use DataTables 3.1.4, bundled with Vite. Teacher Assignments, academic directories, students, guardians, employees, teachers, enrollments, exams, grading, marks review, results, promotion history, PDF downloads, user/role administration, audit history, record pickers and related-record lists share the same controls.

- Search runs against the authorised database query before pagination, including related display names. It searches all matching records, not only the visible page.
- Page sizes are 10, 20, 25, 50 or 100. Existing directory ordering and advanced filters are preserved. There is no unbounded “show all” request.
- Search and pagination update the table without a full page reload. Table state stays in the URL and can be refreshed or shared. Multiple tables have separate search/length/page state.
- The search box replaces the original search field when JavaScript is available. Existing filter forms and pagination remain available without JavaScript.
- Controls stay within the phone viewport; wide table columns scroll horizontally. Loading and retry states are shared.

## Implementation

`TablePagination` is registered as the `paginateTable()` Eloquent builder macro. Controllers and directory services use it for read-only paginated lists. It keeps their existing scope, gates, context filters and business ordering, validates table state, applies whitelisted searches from `config/list-tables.php`, and registers paginator metadata for the shared Blade component. Related searches remain grouped inside the original authorised query. Historical result searches use captured values.

`x-data-table` embeds pagination metadata and retains ordinary HTML rows and fallback navigation. `resources/js/list-tables.js` uses the supported DataTables custom Ajax function to fetch the existing authorised GET page and read its rendered table cells and metadata. This reuses the same escaped Blade content, action permissions, status badges and CSRF-protected row forms rather than maintaining a second JSON view of every directory. Only a bounded database page is returned. New records do not require a new endpoint.

The browser discards superseded requests. Errors show a retry message, and HTML sign-in redirects are treated as failures. No user-entered field names become SQL identifiers; identifiers come from the application configuration and search terms are bound parameters. Existing route permissions, school scopes and creator scopes remain authoritative on every request.

Published class results keep the existing student-name search meaning. Print/PDF/Excel filters follow the current table search, while “Entire Class” continues to ignore student search as before. Selection is limited to the current page and resets after a search or page change; select-all uses the current row inputs after every redraw.

Marks-entry and promotion-processing tables remain ordinary paginated editing forms with their existing 20-row save limits. The small teacher-directory pop-up also keeps its own searchable picker. Printed reports, PDFs and field-by-field audit detail tables are unchanged.

No database migration or live-school data changes are required.

## Verification

`ListTableTest` checks cross-page assignment searching, field and relation configuration, active filters, independent table state, allowed page sizes, invalid input, permissions and school isolation. Browser fixtures are generated from real authorised Laravel responses using fictitious records in a rolled-back `school_erp_testing` transaction. Browser requests reuse those response fixtures; backend queries are exercised separately by feature tests.

Verification completed: **561 regression tests / 3,562 assertions passed**; the final search-field review also passed **6 focused tests / 91 assertions**. Chromium checked **156 page/viewport combinations** across 26 list screens at widths 320, 390, 768, 1024, 1440 and 1920, with 32 Ajax requests covering search, paging, page size, retries, bulk selection and export filters, and no JavaScript errors or DataTables alerts. The no-JavaScript fallback passed. Vite build, Blade compilation, PHP/JavaScript syntax, targeted Pint and whitespace checks passed.

Previews: [Teacher Assignments on desktop](previews/teacher-assignments-table-1440.png), [on a phone](previews/teacher-assignments-table-390.png).

Compatibility checks also passed for the existing class setup (60 page/viewport checks) and teacher picker (six viewport sizes, 72 directory requests, zero JavaScript errors) after restoring the separate subject-checkbox event listener.

## Filter and pagination corrections

Status and other list filters now refresh the table on change. The Filter button also refreshes the same table. Every draw reads the current filter controls, removes cleared values from the URL, retains search/page size, and returns to page one when a filter changes. Percentage lookup forms remain separate from list filters.

Published-result print/PDF fields and Excel links now refresh from the filtered response, including the selected result status and removal of a cleared status. Page selections still reset on redraw.

Pagination uses spaced 44-pixel buttons, compact labelled navigation icons, a high-contrast current page, consistent disabled states, and a responsive number of page tiles. The current-page hover color overrides the DataTables theme correctly. Ordinary HTML pagination receives the same button sizing and wrapping.

Correction verification: **6 focused tests / 91 assertions passed**. Chromium passed **156 page/viewport checks and 292 filter Ajax requests** across six screen widths, covering Active/Inactive/All changes, button submission without navigation, reset from later pages, search and page-size preservation, result export/print status, current-page hover contrast and tile spacing, with zero JavaScript errors. Production build and syntax/whitespace checks passed. Previews: [phone](previews/list-filters-pagination-390.png), [desktop](previews/list-filters-pagination-1440.png).
