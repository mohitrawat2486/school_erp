# Teachers workspace

Open **Employees → Teachers**. The Teachers tab combines identity, specialisation/experience, profile status, current-year active assignment counts and two direct actions: **View profile** and **Assignments**. The All assignments tab opens school-wide assignment management. Users who can view teachers and assignments see a single Teachers sidebar entry, highlighted throughout both workflows. Assignment-only users retain the independent Teacher Assignments entry and do not need additional profile permissions.

Click a teacher's Assignments action to open their responsibilities directly. With an active current year, the link starts with that year and Active status. The teacher's name and employee number identify the selected scope, and the year/type/status/search controls remain available. Reset clears the optional filters while retaining teacher/year and the directory return context. All years and inactive records remain accessible. Existing assignment URLs continue to work.

**Add assignment** preselects the teacher and selected writable year; choosing Class teacher or Subject teacher keeps that context and skips the teacher picker. The existing class, section and subject selectors and confirmation remain in use. Inactive teachers and closed/archived years retain read-only history without offering an invalid creation action. Selecting another writable year updates the action again.

The profile page also has prominent icon actions for editing and assignments. Back navigation preserves directory status, search, page size and page. Assignment details and status changes retain the assignment list's context. Directory counts show active assignment records for the active current year; they do not imply that every assignment is currently effective. Counts and links are hidden from users without assignment-view permission. Without an active current year, the directory explicitly displays No current year instead of a misleading zero count.

## Implementation

- Existing teacher/assignment controllers and routes remain authoritative. Counts use a scoped `withCount` query against the existing teacher assignment relation.
- `TeacherWorkspace` validates structured directory/list query state with bounded searches, pages and page sizes. Return URLs are generated from fixed application routes, never accepted from input.
- Shared workspace tabs and recursive hidden context fields carry validated state through the original forms. Assignment services, eligibility/conflict rules, audit history and school permissions are unchanged.
- Marked workspace headers, tabs and context links update from the rendered response after DataTables draws. Year changes therefore also update creation availability, profile context and Reset/Choose class links.
- `x-action-link` provides consistent eye, pencil, clipboard, selection and print actions with visible labels, tooltips and keyboard focus. Existing SVG icons are extended locally. List actions across the app use this component, with 44-pixel touch targets.

No migration, new package, permission grant or live-school data change is required.

## Verification

`TeacherWorkspaceTest` covers current-year counts, direct and scoped links, preselected creation across every step, creation/status navigation context, teacher and year retention, profile editing, missing current year, closed/inactive history, profile-only and assignment-only permissions, foreign-school denial and invalid navigation input.

Browser previews use fictitious records in a rolled-back transaction in the isolated testing database. The real authorised responses are captured before browser checks; the browser does not persist records.

Previews: [Teachers on desktop](previews/teachers-workspace-1440.png), [Teachers on a phone](previews/teachers-workspace-390.png), [teacher assignments on desktop](previews/teacher-responsibilities-1440.png), [teacher assignments on a phone](previews/teacher-responsibilities-390.png).

Final verification: **571 regression tests / 3,646 assertions passed**, including **10 workspace tests / 84 assertions**. The complete teacher-directory → assignment → detail/back → preselected creation flow passed at six viewport sizes (320–1920 pixels), with 30 Ajax requests and zero JavaScript errors. Shared action/filter checks passed another **156 page/viewport combinations and 292 Ajax requests** across the existing lists. Vite production build, Blade compilation, JavaScript syntax, targeted Pint and whitespace checks passed. Preview fixtures were rolled back; no live-school records were changed.
