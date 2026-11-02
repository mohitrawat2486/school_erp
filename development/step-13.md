# Step 13 — Guardians (plan Phase 11)

## Completed

Reusable guardian contacts, student–guardian relationships, contact editing, primary and emergency flags, sibling sharing and retained unlink history. Routine guardian management lives in Add/Edit Student, with up to three contacts and existing-contact search. Student profiles display current and previous guardian contacts in simple lists and link directly to Edit Student. The separate Guardians sidebar entry is removed; legacy guardian routes remain functional and highlight Students in navigation.

## Files created

- `database/migrations/2026_10_27_200000_create_guardians_and_student_guardians.php`
- `app/Models/Guardian.php`, `StudentGuardian.php`
- `app/Enums/GuardianStatus.php`, `GuardianRelationship.php`
- `app/Policies/GuardianPolicy.php`
- `app/Services/GuardianService.php`, `GuardianDirectory.php`, `StudentGuardianService.php`
- `app/Http/Controllers/GuardianController.php`, `StudentGuardianController.php`
- `app/Http/Requests/SaveGuardianRequest.php`, `GuardianIndexRequest.php`, `SaveStudentGuardianRequest.php`
- `database/factories/GuardianFactory.php`, `tests/Feature/GuardianTest.php`
- `resources/views/guardians/index.blade.php`, `form.blade.php`, `show.blade.php`, `select.blade.php`
- `resources/views/components/guardian-contact-table.blade.php`

Modified: `Student.php` and `School.php` relationships; `StudentController.php` and student details for eager-loaded, paginated contacts; application sidebar; `routes/web.php`; README; compiled frontend assets.

## Database changes

`guardians`: school, first/last names, required phone, optional alternate phone/email/occupation/address, ACTIVE/INACTIVE status and timestamps. Indexed by school/status/name and school/phone. `(school_id,id)` unique prepares composite references. School foreign key restricts deletion.

`student_guardians`: school, student, guardian, FATHER/MOTHER/GUARDIAN relationship, primary and emergency flags, ACTIVE/INACTIVE link status and timestamps. Unique school/student/guardian pair supports repeatable unlink/relink without duplicate records. Composite foreign keys to students and guardians prevent cross-school links even outside application code. Generated nullable `primary_student_id` with school uniqueness enforces at most one active primary guardian per student. Checks restrict relationships, statuses and booleans; inactive links cannot remain primary.

Both tables use school scoping. Student has many guardian links; Guardian has many student links; StudentGuardian belongs to both. School has many guardians. Important records have no physical deletion route. Unlinking retains the row, and every relationship change is audited. The migration is applied to `school_erp`; no real guardian data is seeded.

## Routes

- `GET /guardians`, `/guardians/create`, `POST /guardians`
- `GET /guardians/{guardian}`, `/guardians/{guardian}/edit`, `PUT /guardians/{guardian}`
- `POST /guardians/{guardian}/activate`, `/deactivate`
- `GET /students/{student}/guardians` — legacy searchable selection and contact settings
- `PUT /students/{student}/guardians/{guardian}` — link, edit or relink
- `POST /students/{student}/guardians/{guardian}/unlink` — retain historical link

## Authorization and consistency

Reading uses existing `student.view`; guardian creation/editing/status and student contact management additionally require `student.update`. These are student contact operations, so no new permissions or role grants were introduced. Creating a student alone does not grant guardian management. Cross-school route IDs return 404; policies and services independently check ownership and fresh actor permissions.

Writes lock the school and relevant records in transactions. Primary replacement clears the old primary and records both changes atomically. Idempotent requests avoid duplicate audits. Audit failure rolls back guardian/contact changes. Inactive guardians cannot be linked or edited as a student's contact; unlinking remains available. Deactivation is blocked while the guardian is primary for any student, including siblings. Replace or unlink those primary contacts first. Non-primary historical links remain visible with guardian and link statuses.

## Assumptions / potential issues

Phone is required; last name and other contact fields are optional. Phone and email are searchable but not unique because family members can share them. Search existing guardians before adding one; siblings should reuse the existing record. Editing guardian contact details intentionally updates the shared contact for every linked student; the edit screen explains this. A primary contact is optional, but at most one is permitted. Emergency contacts can be multiple. Unlinked relationships remain visible as history, and audits preserve past settings. No guardian login accounts are created. Existing local MySQL version is unchanged; its previously documented upgrade is a separate maintenance task.

## Manual testing

1. Sign in with `student.view` and `student.update`. Open **Students → Add student** or edit an existing student. In Guardians, choose **Add guardian**, enter name/phone and optional details, then save the student.
2. View a student and choose **Edit guardians**. Add another card, choose an existing contact and search by name or phone without leaving the form.
3. Select Father/Mother/Guardian and primary/emergency settings. Save the student and verify the contact on the profile.
4. Link a second guardian and make them primary. Verify the former primary becomes an additional contact. Check audit logs with `audit.view`.
5. Link the same guardian to a sibling with different relationship/emergency settings. Edit the shared phone and verify both students show the update.
6. Remove a guardian card, check the removal confirmation and save; verify history remains. Use Undo before saving to restore the card. Reuse the existing contact to relink.
7. Try deactivating a guardian who is still primary for either sibling. Verify the validation message; replace or unlink primary contacts and retry. Verify inactive guardians cannot be linked until activated.
8. Verify a view-only user can read contacts but cannot create/edit/link/unlink/change status. Verify foreign-school IDs cannot be accessed.
9. Confirm the sidebar has Students without a separate Guardians entry. Check Add/Edit Student and profile contacts on mobile; legacy guardian URLs should remain accessible with Students highlighted.

## Next recommended phase

Student Enrollment (plan Phase 12): academic-year/class/section placement and roll numbers, preserving historical enrollments.

## Verification

All 196 tests / 1,369 assertions passed on the isolated `school_erp_testing` database, including 14 new guardian tests. Coverage includes CRUD/audits/validation, shared sibling contacts with independent settings, primary replacement, idempotency, unlink/relink, inactive/primary deactivation rules across siblings, forbidden field/flag/relationship input, guests and read-only users, cross-school endpoints/service checks, search/status/pagination, audit rollback and database protection against mixed-school links, duplicate primaries and invalid relationships.

Production Vite build, Blade compilation, Pint and changed-code whitespace checks passed. Chromium checked six screens (directory, create, show, edit, student detail and contact manager) at 320, 390, 768, 1440 and 1920 pixels. Checks covered document overflow, active navigation, persisted relationship/primary/emergency settings, mutation URLs and pagination. Preview fixtures were created in a rolled-back testing-database transaction.

Previews: [directory](previews/guardians-desktop.png), [contact manager](previews/guardians-contacts-desktop.png), [mobile contact manager](previews/guardians-contacts-mobile.png).

Navigation simplification verification: **45 related tests / 419 assertions passed** on `school_erp_testing`, covering guardian management, inline contact editing, sibling reuse, primary/emergency preferences, retained history, permissions and student profile contacts. Blade compilation and whitespace checks passed. This change removes the sidebar entry and highlights Students on legacy guardian pages; existing records, relationships and endpoints are retained. No migration or frontend asset rebuild is required.
