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
