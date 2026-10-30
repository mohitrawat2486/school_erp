# Teachers in class setup

Create and Edit Class now include an optional Teachers panel below Subjects. Choose a class teacher per section and a subject teacher for all selected sections. Expand **Different teachers by section** to make exceptions. Section and subject changes update this panel automatically.

**Choose teacher** opens a searchable directory with name, specialisation and employee number. Search results contain only active teachers with active employee records in the current school, in pages of 12. The picker supports keyboard navigation, Escape, focus restoration and full-screen use on phones. Search failures offer a retry. Choices stay in the form until the class is saved; closing the picker does not save anything.

Existing assignments are prefilled when editing. Clear removes active coverage for the chosen section/subject; replacement deactivates previous assignments and preserves their history. Selecting a former teacher reactivates the matching historical record. Untouched co-teachers remain assigned. Changing one section of a legacy whole-class assignment preserves other sections by converting its coverage to section assignments and retaining the original record as inactive. Choosing one teacher for a row with several teachers replaces those teachers for the selected sections.

An inactive teacher remains visible on existing assignments but cannot be chosen for a new assignment. If changing a whole-class assignment would require creating section assignments for an inactive teacher, the save is rejected: replace or clear that teacher for all affected sections. No partial changes are saved.

## Saving and access

The current active academic year and active class are required for new teacher choices. Teachers are optional. With JavaScript disabled, class/section/subject saving remains available and assignments can be made through the existing Assign Teachers screen.

The existing class create/update and teacher-assignment view/manage permissions apply. Section and subject management permissions are required only when changing those parts of the setup. A class manager with teacher-assignment permissions can change teachers against existing sections and subjects without their management permissions. The picker is authenticated, school-scoped, private/no-store, limited to directory fields, and throttled to 120 requests per minute.

Class, sections, subjects, teacher assignments and audit events save in the same school-locked transaction. Existing class-teacher conflict, curriculum eligibility and assignment history rules are reused. Form revisions include assignment state, so concurrent assignment changes reject stale edits and refresh the teacher draft. Other validation errors retain the draft. Unused section deletion and protection for referenced sections/subjects remain in effect.

## Implementation

- `ClassTeacherSetup` synchronises only changed section/subject slots through `TeacherAssignmentService`.
- `ClassTeacherRules` validates up to 500 draft changes with tenant and active-employee checks.
- `ClassTeacherPickerController` serves the paginated directory at `GET /academics/classes/teacher-options`.
- The shared class form includes `classes/partials/teachers.blade.php`; `class-teacher-setup.js` manages draft choices and the native dialog.
- Create/Update Class orchestration calls the teacher synchroniser inside its existing transaction. No schema changes or added dependencies.

## Verification

`ClassSetupTest` covers creation, prefill, shared subject teachers, custom sections, historical replacement/reactivation, whole-class and co-teacher preservation, clearing, draft recovery, stale edits, tenant and eligibility checks, permissions, pagination/search and rollback. Browser previews use fictitious fixtures in a rolled-back transaction in `school_erp_testing`; picker responses are intercepted in the browser checks while the real endpoint is covered by feature tests.

Screenshots: [phone picker](previews/class-teacher-picker-390.png), [desktop picker](previews/class-teacher-picker-1440.png), [phone panel](previews/class-teachers-panel-390.png), [desktop panel](previews/class-teachers-panel-1440.png).

Final verification: **555 regression tests / 3,471 assertions passed**, including **37 class setup tests / 308 assertions**. Chromium passed the 60 existing class page/viewport checks and teacher-picker interactions at six widths (320–1920 pixels), making 72 mocked directory requests with zero JavaScript errors. The final no-JavaScript fallback was also checked. Vite production build, Blade compilation, PHP/JavaScript syntax, targeted Pint and whitespace checks passed. No migration or live-school data changes were required.
