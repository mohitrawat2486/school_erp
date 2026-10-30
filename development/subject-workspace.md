# Subjects workspace

Open **Academics → Subjects**. The Subjects tab lists the school's subjects, current-year active class counts and direct **View** / **Classes** actions. **By class** provides a year/class-filtered view of subject use and marks settings. Both use one Subjects sidebar entry. New workspace links start with the active current year where available, while existing URLs and the All years filter retain access to historical records.

## Manage several classes together

Choose a subject → **Classes → Manage classes**. The subject is fixed and the academic year is selected before changing classes. A searchable checklist shows current assignments, available classes and previously assigned classes. Existing links used by teachers or exams stay selected with an explanation; active links to inactive classes are also retained in the checklist. Class search never removes hidden selections.

The summary shows additions/restorations and removals. Removing an unused class requires reviewing its name and confirming the change. Changing the removal choices clears that confirmation. Removal marks the class-subject link inactive, retaining its identity, marks settings and history. Selecting it again restores the same record and settings. New links start with blank marks settings. **Marks settings** on each class row configures that class/year independently.

All selected class changes and audit records save together in a school-locked transaction. Referenced mappings are protected again on the server, including through the original deactivation endpoint. A revision includes subject/year/class state and membership/marks values, so stale checklists cannot overwrite changes made while the page was open. Existing active links are not rewritten during a no-op save. An audit or later change failure rolls everything back.

Draft and active years can be configured. Closed/archived years and inactive subjects show a disabled checklist and clear explanatory text. School-wide subject availability remains separate from class use. Existing class creation, subject selection and teacher/exam workflows continue to use the same mapping records.

## Navigation and access

Catalog and class-list query state is validated and carried through view, edit, marks and checklist forms. Reset keeps the chosen subject/year/class context. Shared DataTables updates marked headers and navigation links after filters change, so the year, class and writable actions remain consistent. Return links are generated from application routes, not supplied URLs.

Existing `subject.view` and `subject.manage` permissions apply. Viewers can inspect classes and history; management requires the existing class-subject creation/update policy. Year, class and subject queries remain school-scoped. No migration, new dependency, permission grant or live-school data change is required.

## Files

- `SubjectClassesController`, `SaveSubjectClassesRequest`, `SyncSubjectClasses` implement the checklist and atomic save.
- `SubjectClassState` handles revision checks and teacher/exam dependencies.
- `SubjectWorkspace` validates bounded catalog/mapping navigation state.
- Existing subject/class-subject controllers, forms and sidebar use the consolidated workspace and shared action links.
- `subject-class-setup.js` supplies class search, change summaries, removal confirmation and year selection. Ordinary HTML forms remain usable without JavaScript.

## Verification

`SubjectWorkspaceTest` checks counts, links, checklist protection, bulk creation/reactivation, preserved marks/identity, confirmed removals, dependency rechecks, stale forms, atomic rollback, inactive/closed restrictions, tenant isolation, read-only access, missing current year and marks navigation.

Browser previews use fictitious fixtures in a rolled-back `school_erp_testing` transaction. Browser checks reuse the captured authorised HTML responses and do not persist records. Previews: [Subjects desktop](previews/subjects-workspace-1440.png), [Subjects phone](previews/subjects-workspace-390.png), [class list desktop](previews/subject-classes-1440.png), [class list phone](previews/subject-classes-390.png), [checklist desktop](previews/subject-class-checklist-1440.png), [checklist phone](previews/subject-class-checklist-390.png).
