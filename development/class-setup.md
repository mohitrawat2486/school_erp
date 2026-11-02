# Class creation with sections and subjects

Open **Academics → Classes → Add class**. Enter the class name, select sections, choose subjects for the current academic year, then click **Create class**. Class code and display order are automatic. Student limits are optional. Display order is automatic and is not shown in the class screens.

## Staff workflow

- On Classes, choose **How it works** beside Add class for a short guide to classes/sections, class versus subject teachers, changing teachers and an example. The guide opens on request in a modal so the list remains uncluttered. Close with Got it, the close button, Escape or a backdrop click; focus returns to the help button. Without JavaScript, the same guide is available as an expandable section.

- Names such as **Class 2**, **Nursery** and **LKG** generate **CLASS-2**, **NURSERY** and **LKG**. The form previews ordinary codes; complex names are confirmed after saving. Codes are generated on the server, so submitted overrides are ignored. Names that generate an existing code receive a clear validation message.
- Section A starts selected for staff who can manage sections. Choose A–D, add custom names such as Blue, set optional student limits, or select **I’ll add sections later**. Duplicate section names are rejected without saving part of the setup.
- The active current academic year appears above a searchable list of active school subjects. Selection applies to every section. **Copy subjects from another class** replaces the selection with active subject mappings from that same year; review the visible selection before saving. It copies subject choices, not marks settings.
- Subject choices can be left empty. Without a current year or an active subject catalog, the page explains how to finish setup later; classes and sections can still be created.
- Confirmation reports how many sections and subjects were created. The class page lists sections, optional limits and current-year subjects, with an authorized **Assign teachers** shortcut. The class directory also shows its sections.

Class editing uses the same setup form, prefilled with active sections, student limits and current-year subjects. Renaming regenerates its code; existing legacy codes are preserved when the name is unchanged. Existing enrollments and published records are retained. Separate section/subject management screens remain available.

## Implementation

- `app/Support/ClassCode.php`: deterministic, bounded code generation. Long/non-Latin names have stable fallbacks within the existing 50-character limit.
- `app/Support/ClassSetupRules.php`: shared creation validation for section rows and active/current-year subject mappings.
- `app/Services/CreateClassWithSetup.php`: one school-locked transaction reusing class, section and class-subject services. A failure rolls back the class, sections, subject mappings and accompanying audit rows together.
- `app/Services/SchoolClassService.php`: automatic codes and default ordering, existing unique checks and audits.
- `app/Http/Requests/SaveSchoolClassRequest.php`: normalization, school-scoped validation, setup authorization and readable errors.
- `app/Http/Controllers/SchoolClassController.php`: setup choices, paginated directory, eager-loaded section summaries and detail data.
- `app/Http/Controllers/TeacherAssignmentController.php`: preserves the chosen class/year while picking a teacher from the next-step shortcut.
- Class form/index/detail views and `resources/js/app.js`: optional interactive controls, search, selection summaries, custom rows, copy preview and skip options.

The existing `class.manage`, section and subject permissions remain independent. Class-only operators can still create classes and cannot inject section or subject mappings. Foreign/inactive subjects, stale current years and foreign records are rejected. No schema, grants or dependencies changed. No live academic data is created by deploying the feature.

New setup limits are 50 sections and 500 subjects per request. Marks maxima/passing marks remain unset on these new subject mappings and are configured separately through the existing subject/exam setup workflow. Existing mappings and exam/result snapshots are not rewritten.

The core form supports ordinary submission without JavaScript. JavaScript enhances code previews, search/copy controls, additional custom section rows and optional skip behavior.

## Verification

Focused tests cover complete creation and audits, no-year/optional setup, duplicate sections, invalid capacities/subjects, wrong schools, changed academic years, permission boundaries, atomic rollback, code regeneration and preservation of existing relationships. The full existing regression suite is also run against `school_erp_testing`. Browser previews use fictitious fixtures inside a rolled-back testing transaction.

Full regression: **525 tests / 3,223 assertions** passed before the final teacher-shortcut refinement. Final targeted class, setup and teacher checks: **46 tests / 330 assertions** passed. Vite production build, Blade compilation, Pint and whitespace checks pass.

Chromium passed **42 page/viewport combinations** across 320, 390, 768, 1024, 1440 and 1920 pixels, with no page errors or page-level horizontal overflow. Interactive checks cover automatic code preview, sections and limits, custom rows/removal, skip/re-enable, subject copying, search without losing selections, validation recovery, missing-year and permission-limited states. Basic controls also work with JavaScript disabled. Validation tests retain the browser session cookie across the redirect to verify real error alerts and field highlighting with JSON sessions.

Previews: [desktop creation](previews/class-setup-create-1440.png), [mobile creation](previews/class-setup-create-390.png), [desktop summary](previews/class-setup-show-1440.png), [mobile summary](previews/class-setup-show-390.png).

## Class screen design refresh

The creation and detail screens use the full content width. Class details and sections sit in equal-width cards on desktop; the subjects card spans the full row below. Cards stack on mobile. Shared card headers, icon badges, breadcrumbs, selected-subject states and action panels keep the presentation consistent. Optional custom section names stay inside an expandable panel. Display order remains hidden.

The generated class code appears in a labeled, read-only input, with live updates and an accessible announcement on the creation form. It has no submitted name; the server continues to generate the authoritative code. On the details page, staff can select and copy the code. No extra UI library or dependency was introduced.

Final design checks: **24 class/setup tests, 164 assertions**, **42 responsive page checks**, Vite build and Blade/PHP template checks passed. Browser checks verify the desktop card alignment and full-width subjects on both creation and detail screens, mobile stacking, and that typing cannot alter the class code. Updated previews are linked above.

## Full Edit Class workflow

**Edit class** now contains the same sections and subjects as creation, with saved values selected automatically. Saving is atomic across class details, section limits and subject choices. Existing section names identify retained sections within the class; subject IDs identify current-year mappings. Re-selecting a retained inactive record reactivates it instead of creating a duplicate; adding a section that was previously deleted creates a new section record. Existing subject marks settings are retained.

Unused unchecked sections are deleted when saving, removing the class relationship entirely. Unchecked unused subject mappings still become inactive. Sections with enrollment, teacher, promotion or published-result references remain selected and cannot be deleted. Subject mappings referenced by an exam or assigned teacher also remain selected, including when copying another class’s subjects. Both the browser and server enforce this. Existing active mappings to inactive catalog subjects are retained and listed separately. Other academic years and published snapshots are not changed.

Inactive classes display their setup with disabled controls and guidance to activate the class first. Class-name editing still works. Operators with class-only permissions retain name/code editing but cannot submit section/subject changes, even empty selections. Older requests omitting setup fields preserve those records.

`UpdateClassWithSetup` uses the existing school lock, authorization and child services in one transaction. `ClassSetupState` supplies a revision fingerprint to reject stale edits. Reloading after a stale-edit error displays the latest server selections, preventing accidental removal of newly added records. Section-capacity errors are mapped back to the relevant row for inline feedback. No migration, permission grants or dependencies were added.

Verification: **20 focused setup tests / 156 assertions** passed. Chromium passed **60 page/viewport combinations** across 320, 390, 768, 1024, 1440 and 1920 pixels, covering create/edit, protected choices, copying, missing-year, inactive and permission-limited states. Preview fixtures were rolled back in the isolated testing database. Full regression: **536 tests / 3,312 assertions passed**. Vite build, Blade/PHP template compilation, JavaScript syntax, Pint and whitespace checks also pass.

Edit previews: [desktop](previews/class-setup-edit-1440.png), [mobile](previews/class-setup-edit-390.png).

## Removing unchecked sections

Both class lists and class details display active sections only. Saving Edit Class deletes unchecked unused sections instead of marking them inactive. It also removes old unused inactive sections left unchecked by the earlier workflow. No class/section relationship remains for a deleted section. Read-only page requests never delete data.

`SectionService::deleteUnused` rechecks school ownership, section-management authorization and all section foreign-key dependencies under the school lock. `Section::withUsage()` loads existence flags for enrollments, teacher assignments, source/target promotion batches and published results in one query. Used sections stay protected, and legacy inactive sections with history remain retained. Database foreign-key protections are kept enabled. Each successful removal records a `SECTION_DELETED` audit with the previous values; an audit or later save failure rolls deletion back. Subject removal behavior is unchanged.

Focused verification: **58 tests / 404 assertions** passed, including actual database removal, no remaining class relationship, audit records, foreign-school denial, historical protections, promotion-only references, inactive filtering and rollback. Full regression: **542 tests / 3,346 assertions passed**. Vite build, Blade/PHP compilation, Pint and whitespace checks passed.

The five previously unchecked, unused inactive sections A–E for local Class 3 (`test123`) were removed with the same audited service after a dry-run dependency check. The bounded maintenance helper is `development/maintenance/prune-unused-inactive-sections.php USER_ID CLASS_ID [--dry-run]`; it operates only on unused inactive sections of that operator’s school and the specified class.

A repeat dry-run confirmed that Class 3 has no unused inactive sections left to prune; its four active sections were retained.

## Teachers in the same form

Create and Edit Class now offer an optional Teachers panel with a searchable picker, per-section class teachers and shared or per-section subject teachers. The class-page shortcut opens this panel for class managers; the standalone assignment workflow remains available. See [teacher setup notes](class-teachers.md) for saving behavior, history protection and verification.

## Classes help modal

The reference image's rules banner and lower example/subject notes are consolidated into the Classes help guide. Content describes the actual application behavior: one active class teacher covering a section, separate subject-teacher roles, class edits saved together and previous assignment history retained. The reference's restriction that a teacher can be class teacher of only one section is not enforced by the existing service and is not asserted by this guide. This UI change introduces no assignment-policy or database changes.

The native dialog reuses existing modal styles, with a scrollable body and fixed header/footer on phones. Initial focus moves to its heading, Tab/Shift+Tab stay within the modal and dismissal restores the help trigger. A single instruction block moves from a native details fallback into the modal when JavaScript starts, avoiding duplicated content. Class filters, search, pagination and editing controls remain independent of the guide.

Verification: **73 class/setup/assignment tests / 566 assertions passed**. Production build, Blade compilation, JavaScript syntax and whitespace checks passed. Chromium verified opening/dismissal, keyboard focus, read-only access, unchanged filters/URL, mobile overflow/footer and no-JavaScript expansion at 320, 390, 768 and 1440 pixels. Browser fixtures used a rolled-back testing-database transaction with intercepted read-only requests.

Previews: [desktop guide](previews/class-help-1440.png), [mobile guide](previews/class-help-390.png).

## Classes directory redesign

The Classes directory follows the supplied visual reference with four permission-aware summary cards, compact filters, Sections/Classes tabs, assignment coverage, subject chips, status badges and icon actions. The instructions remain in How it works. Sections is the default view for users with section access; users with only class access see Classes. Inactive sections and sections of inactive classes stay out of the section roster. Inactive classes remain available from the Classes view and status filter. Class and section references use the saved class code; no extra section-code column is created.

Select an academic year to see its active enrollment counts, subject mappings and active assignments for eligible teachers. A whole-class teacher assignment covers each section. Teacher’s subjects lists only the subjects that the section’s class teacher teaches there, including whole-class subject assignments. Total subjects counts the class curriculum. Pending means an active section lacks a class teacher; classes are pending when they have no active sections or any active section lacks one. Active/Assigned filters select completed teacher coverage when a year and assignment access are available. Without a selected/current year, counts show a dash with guidance instead of borrowing another year’s data.

Search covers visible class/section/reference, teacher and subject information under the corresponding permissions. The shared DataTables integration supports an opt-in external search field on this screen, keeping search in the filter toolbar without duplicating it in the table. Paging and length remain server-side; filters, year context, coverage and export URLs synchronize after draws. Other lists retain their existing search controls. Statistics summarize school-wide classes and active sections; student/teacher figures follow the selected year.

Export downloads all matching rows as CSV with the same search, filters, school scope and visible columns. It streams in bounded batches and protects spreadsheet formula cells. Student identities and Aadhaar values are not exported; only permitted enrollment totals are included. Bulk Setup has been removed from the directory. Use Edit Class to update sections, subjects and teachers together; subject/teacher changes use the school’s current year. Assign links for other editable years open the assignment workflow with the selected year retained; closed/archived years have no Assign action. No directory redesign action changes records merely by viewing the screen.

Implementation: `ClassDirectory` supplies school-scoped queries and page data; `SchoolClassIndexRequest` validates filters; `SchoolClassController` delegates listing and CSV export. The directory-row partial and scoped CSS provide the presentation. No migration, permission grant, dependency or live-school data changes are required.

Verification: **93 related tests / 782 assertions passed**, covering class/section views, actual counts, class-wide coverage, teacher subjects, selected/missing years, combined filters, search/pagination bounds, hidden inactive sections, permissions, school scope, CSV completeness/formula protection and year-aware Assign links. Chromium checks passed at 320, 390, 768, 1440 and 1920 pixels for toolbar search, filters, both views, pagination, export synchronization/download, help, retries, no-year/read-only states and the no-JavaScript fallback. Fixtures were rendered in a rolled-back testing transaction; browser requests were intercepted and performed no writes. Production build, Blade compilation, targeted Pint, JavaScript syntax and whitespace checks passed.

Previews: [desktop Classes directory](previews/classes-directory-1440.png), [mobile Classes directory](previews/classes-directory-390.png).
