# Full-width lists

All 16 table views (directories and selection pickers) use the available workspace width, including when the sidebar is collapsed. The permissions catalog uses the same full-width layout with responsive cards. Forms and detail pages retain a readable maximum width.

Shared Blade components provide table headings, actual record counts, status badges, consistent row actions and accessible pagination. Filters retain their existing routes and query parameters. Pagination uses the server paginator, including first/last pages and disabled states. No database or permission changes are required.

Tables scroll inside their panels on small screens; action columns remain visible on desktop and scroll with other columns on phones to keep names readable. Filter controls stack and touch targets expand on phones.

Validation:

- Production Vite build and Blade template compilation passed.
- Existing regression suite: 168 tests, 1,131 assertions passed.
- Chromium checked 13 rendered list/picker pages at 320, 390, 768, 1440, 1920 and 2560 pixels: full-width alignment, no document overflow, pagination URLs, status badges and sidebar expansion passed.
- Preview records were created inside a rolled-back transaction in the isolated testing database.

Previews: [desktop](previews/lists-desktop.png), [collapsed sidebar](previews/lists-desktop-collapsed.png), [mobile](previews/lists-mobile.png).


## Subjects and Students overview (2026-11-02)

Subjects and Students now share the Classes/Sections directory styling: four overview cards, a compact full-width table, icon actions, external search, responsive filters and the existing DataTables pagination. Their How it works guides reuse the accessible help-dialog behavior with keyboard focus and a no-JavaScript details fallback through `components/directory-help.blade.php`. No bulk setup action is added.

Subjects counts total, active, inactive and active subjects without class assignments in the active current academic year. The catalog keeps its By class tab and navigation context, including after Ajax search/filter changes. Subject rows expose separate code/type columns and View/Edit/Classes actions according to permissions.

Students counts total, active, inactive and students with an active enrollment in the current year. Rows eagerly load that year's active placement to display class, section and roll number without one query per student. Historical, withdrawn and transferred placements are excluded from these columns. Student status remains separate from enrollment status. The existing class-dependent section filter and Move to next academic year dialog remain intact, including after table updates.

Overview totals remain school-wide while filters apply to the list. Missing academic-year context displays a dash for year-dependent counts. Static `subjects/export` and `students/export` routes share their controller's filtered list query, export every matching page, stream results in batches and escape spreadsheet formulas. Student exports contain admission number, name, class, section, roll number, year and status; Aadhaar, guardian contacts and private profile fields are excluded. Existing module view permissions and school boundaries apply to both exports.

Validation: **82 feature tests / 820 assertions passed**, covering the new overviews/exports and the existing subject catalog, class mapping, student, class/section filter, year-end and shared-list behavior. Chromium verified both pages at 320, 390, 768, 1440 and 1920 pixels: cards, search, filters, dependent section clearing, pagination, export synchronization, modal keyboard controls, year-end launch, read-only/missing-year states, retry and no-JavaScript fallback. The run intercepted 92 GET requests with no writes or JavaScript errors. Vite build, Blade compilation and whitespace checks passed. Preview data was generated in a rolled-back testing database transaction.

Previews: [Subjects desktop](previews/subjects-directory-1440.png), [Subjects phone](previews/subjects-directory-390.png), [Students desktop](previews/students-directory-1440.png), [Students phone](previews/students-directory-390.png).
