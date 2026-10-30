# Class creation with sections and subjects

Open **Academics → Classes → Add class**. Enter the class name, select sections, choose subjects for the current academic year, then click **Create class**. Class code and display order are automatic. Student limits are optional. Display order is automatic and is not shown in the class screens.

## Staff workflow

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
