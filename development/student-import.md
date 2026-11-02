# Student import

Open **Students → Import students** (`/students/import`). The existing app layout, Student directory, create/edit forms and sidebar remain in place.

## Existing architecture and schema

`Student` owns admission number, first/middle/last names, gender, date of birth, admission date, blood group, address, private photo path, active/inactive status and encrypted Aadhaar. Core form rules now live in `StudentRules`, reused by `SaveStudentRequest` and import validation. The import saves through `StudentService`, preserving encryption, school scope, audits and existing creation behavior.

Class, section, academic year, numeric roll number and joining date belong to `StudentEnrollment`. Enrollment creation still runs through `EnrollmentService`, including active-master checks, class/section membership, dates, school/year/student uniqueness, roll reservation and section capacity. Student phone/email/category fields do not exist. Phone/email and parent details belong to `Guardian`, linked through `StudentGuardian`; the existing guardian editor/services support three contacts and one primary contact.

The app uses Blade, plain JavaScript, existing form controls/icons/dialogs/alerts and DataTables for read-only directories. Import uses a separate editable HTML grid with 25 rows per page, sharing the app's styling. It does not initialize DataTables over live cell editors. Existing student creation, inline guardian/enrollment editing, year-end progression and CSV export remain available.

## Supported import fields

- Student: admission number, Aadhaar number, first name, middle name, last name, gender, date of birth, admission date, blood group and address.
- Full student name is an explicit mapping adapter to the existing first/last fields. The first word becomes first name; the remainder becomes last name. Multiword names receive a warning explaining the split. Map separate name columns when this split is unsuitable.
- Placement: class name/code, section name, academic-year name, roll number and enrollment joining date.
- Contacts: father name/phone/email, mother name/phone/email and guardian name/phone/email, creating at most three contacts through the existing guardian editor. Contact full names use the existing first/last fields. The first supplied contact in father/mother/guardian order becomes primary. No shared guardian is modified or silently linked.
- There is no import mapping for category, student phone/email, status, photo, arbitrary IDs, school ownership, withdrawal, transfer or promotion.

The centrally maintained labels and aliases are in `config/student-import.php`. Required mappings are admission number, Aadhaar and either full student name or first name. Admission date must be mapped or supplied as an explicit default setting. Placement and contacts are optional; their existing additional permissions still apply.

## Paste, mapping and corrections

The parser accepts copied tab-separated spreadsheet text, CR/LF line endings and quoted multiline cells with doubled quote escapes. It preserves empty cells, trailing empty columns and internal spaces, trims surrounding whitespace and removes trailing completely blank rows. Inconsistent row widths are retained and blocked; the user can correct cells or explicitly fill missing cells with blanks.

Known headers can auto-map conservatively. Examples include First name, Admission no/Admission #, Aadhaar, DOB, Class, Section and Roll number. Header detection requires at least two recognized fields. Generic Phone and Email are deliberately not auto-mapped because there are several guardian contact types and no Student contact fields. Duplicate header matches remain unmapped. The UI prevents duplicate target mappings and mixing full-name with separate name mappings; the server enforces the same restrictions.

Paste immediately creates a preview. Continue collapses the paste panel, which remains available for inspection/editing. Mapping dropdowns sit above the editable grid. Date format, default admission date and default academic year remain visible. Cell edits invalidate the review immediately and trigger debounced server revalidation once validation has begun. Changing the raw paste requires parsing the changed text again. Network/API errors keep raw data, mappings and edits in memory.

## Validation and status colors

Green/check means valid, red/cross means blocking error, yellow/exclamation means a non-blocking warning, and neutral means ignored or not checked. Messages and native titles accompany colors; cell editors have labels, error state and associated descriptions. Row status is invalid if any field or row error exists, warning if only warnings exist, otherwise valid.

The server uses shared Student rules, batched admission-number checks, current-school active master data, existing guardian rules and enrollment constraints. It checks required fields, lengths, 12-digit Aadhaar, supported gender/blood group, DOB/admission ordering, real dates, active year/class/section relationships, numeric roll range, reserved/pasted roll duplicates and capacity across candidate rows. Import phone checks additionally reject invalid characters and require 7–15 digits with supported phone punctuation. Email uses the existing guardian email rule.

Safe gender normalization includes M/male/Male → MALE and F/female/Female → FEMALE. Class names/codes are matched conservatively; Class/Grade prefixes can resolve only when unambiguous. Missing or ambiguous master data blocks the row. Case/accent-folded admission keys conservatively catch in-sheet duplicates, including those that could collide with the database's case-insensitive unique index; existing database numbers are checked in batches of 500.

The user explicitly chooses DD/MM/YYYY, MM/DD/YYYY or YYYY-MM-DD. Day/month input also accepts hyphens; ISO dates are accepted directly. Date conversions and gender interpretations receive warnings. Numeric Excel date serials and guessed mixed regional formats are not accepted. Empty mapped optional values receive warnings. Unmapped optional fields do not create warnings.

Summary filters show all, valid, warning or invalid rows. Review is unavailable until the latest server validation succeeds. Warning rows are excluded unless the user selects **Include rows with non-blocking warnings**. Invalid rows are always skipped. Confirmation states exactly how many students are selected and requires a review checkbox.

## Endpoints and persistence

All endpoints use the existing authenticated school-scoped web middleware. POST requests use JSON, session authentication, CSRF and the app's usual 422 validation response. Both POST endpoints are throttled at 60 requests/minute per authenticated user.

- `GET /students/import`: import page and permitted configuration.
- `POST /students/import/validate`: `{rows, mappings, settings}`. Returns normalized values, per-field messages/status, row statuses, summary, a review token, chunk size and expiry.
- `POST /students/import`: `{review_token, chunk, include_warnings}`. Accepts only a server-issued review. Returns imported/skipped row references, counts, completion and replay state. Raw replacement rows in an import request are never used.

Review data is encrypted in the application's configured cache, scoped to the school/user and expires after 30 minutes. It includes the original input and fingerprints of the reviewed resolved data. The browser holds no persistent clipboard/local-storage copy. Validation responses disable caching.

Saving uses ordered batches of up to 100 raw rows. Each batch locks the school, checks fresh permissions and revalidates original data against current database state before writing. Newly invalid, unconfirmed-warning or changed resolved data is skipped with a reason. Originally invalid/unselected rows cannot become eligible without a new review. Explicit roll numbers are reserved before allocating automatic rolls; automatic allocation never consumes a later pasted manual roll, and overflow is rejected during review.

Student, guardian, enrollment and audit writes in a batch commit together. A service validation failure identifies its data-row number and rolls back that batch. An unexpected failure also rolls back the current batch. Earlier confirmed batches remain saved and are shown in progress/summary. No all-or-nothing promise is made for the entire 2,000-row import.

A `STUDENT_IMPORT_CHUNK` receipt is saved in the existing immutable audit log in the same transaction. Repeating a school/user/token/chunk returns the original result, including after the review cache expires, without inserting duplicates. Chunks must be processed in order and keep the confirmed warning setting. The UI counts row references rather than adding replayed counts. Existing per-student/contact/enrollment audit records remain intact; Aadhaar remains encrypted and redacted from audits. Import receipts contain IDs/counts/reasons, not pasted student records.

## Permissions and database changes

The dedicated `student.import` permission is required alongside existing Student view/create access. Guardian/enrollment import additionally requires the existing `student.update` permission. The entry link is hidden without access; page, validate, import and service methods enforce authorization. Permission changes are rechecked before each batch.

The only new migration adds the permission and grants it to existing SCHOOL_ADMIN/SUPER_ADMIN roles that already have student creation access, consistent with the existing school administrator provisioning policy. Other staff roles require an explicit RBAC grant. New school provisioning includes the permission through the existing permissions configuration. The migration is applied to the app database.

No Student fields, parallel Student models, import batch tables or error tables were added. Existing cache and audit tables provide temporary reviews and durable receipts.

## Files

- `app/Support/StudentRules.php`; updated `SaveStudentRequest`.
- `config/student-import.php`, `config/permissions.php` and the permission migration.
- `StudentImportController`, `StudentImportRequest`, `StudentImportValidator`, `StudentImportService`.
- `resources/views/students/import.blade.php`; Student directory entry link.
- `resources/js/student-import-data.js`, `student-import.js`, app entry import and scoped app CSS.
- Import routes in `routes/web.php`.
- `tests/Feature/StudentImportTest.php` and `tests/Frontend/student-import.test.mjs`.

## Verification

Final regression run: **142 tests / 1,170 assertions passed**, including **21 import feature tests** and the existing Student, guardian, enrollment, year-end, directory and access coverage. All **10 frontend parser/mapping/status cases** pass. Production Vite build, Blade compilation, targeted Pint, JavaScript syntax and whitespace checks pass.

PHP coverage includes basic read-only review, required/malformed values, date-format normalization, warning consent, DB/in-sheet admission duplicates, invalid class/section relationships, roll conflicts/capacity/overflow, guardian phone/email validation, three contacts, encryption/audit redaction, revalidation after DB changes, dedicated permissions/revocation, school/user boundaries, expired review, out-of-order chunks, 101-row batching, idempotent receipts, batch rollback and row-specific late errors. A 1,200-row validation test asserts fewer than 50 database queries.

Run `php artisan test --compact --filter=StudentImportTest` and `node --test tests/Frontend/student-import.test.mjs`. The Node cases cover TSV parsing, quoted multiline/escaped cells, empty cells, inconsistent widths, limits, conservative aliases, duplicate/name mapping conflicts, missing required mappings, cell/row status, corrections and 1,200-row parsing.

Chromium checks use real page/API snapshots captured inside a rolled-back isolated testing transaction. They cover 320, 390, 768, 1440 and 1920 pixel widths; paste preview, mappings, colors/messages, normalized values, filters, inline revalidation, warning selection, confirmation, completion, API failure/retry, 1,200-row pagination, inconsistent widths, safe user text and paste events. No browser requests create live-school records.

Previews: [desktop](previews/student-import-1440.png), [phone](previews/student-import-390.png).

## Limits and next improvements

This is a create-only clipboard import, limited to 2,000 rows, 40 columns and approximately 2 MB at a time. Existing students are never updated. Pasted data stays in the current browser page; refreshing/closing it loses unsaved edits. Large source sheets should be split. The current confirmation covers multiple atomic batches; it does not promise whole-file rollback. Review tickets last 30 minutes; validate again after expiry. Unexpected failures can be retried using the same receipt identity.

Phone/email import creates new guardian contacts; automatically reusing shared contacts requires an explicit matching/review workflow before it should be added. Fuzzy duplicate-name matching, Excel file uploads/serial dates, photo import, import history screens and background jobs for larger imports are suitable next steps. No unsupported category or direct Student phone/email fields were invented for this feature.
