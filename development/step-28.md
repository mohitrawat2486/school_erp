# Step 28 — Audit Logging (plan Phase 26)

Open **Administration → Audit logs**. Filter by module, action, actor ID, record type/ID and inclusive date range. The list shows 25 entries per page, newest timestamp/ID first, and preserves filters across pages. **View changes** shows changed fields side by side, full original JSON, request metadata and captured context. Missing keys and explicit nulls remain distinguishable.

## Required coverage

The existing `audit_logs` table already contains every field requested in Phase 26. Existing services record changes inside the same transaction as their business operation:

| Requirement | Existing/final events |
| --- | --- |
| Marks changes | `MARKS_DRAFT_SAVED`, `MARKS_SUBMITTED` with before/after marks |
| Marks review/unlock | `MARKS_VERIFIED`, `MARKS_LOCKED`, `MARKS_UNLOCKED`, including unlock reason |
| Result generation | `RESULT_GENERATED`, exam review-start event |
| Result publication | `RESULT_APPROVED`, `RESULT_PUBLISHED`, `RESULT_SNAPSHOTS_ARCHIVED` |
| Roles | `ROLE_CREATED`, `ROLE_UPDATED`, `USER_ROLES_CHANGED` |
| Permissions | `ROLE_PERMISSIONS_CHANGED` |
| Account state | `USER_CREATED`, `USER_UPDATED`, plus explicit `USER_DISABLED` / `USER_ENABLED` on actual state transitions |
| Student academics | Enrollment creation/update/status changes and promotion outcomes/batches |

Academic years, classes, sections, subjects, employees, students, guardians and grading changes retain their existing coverage. No duplicate generic event system was added. Failed business operations roll back their logs; failed audit writes propagate and roll back the accompanying business changes.

## Context, privacy and history

Migration `2026_10_28_070000_complete_audit_logging.php` adds nullable JSON `context`, school/module/time and school/action/time indexes, and MySQL triggers that reject UPDATE and DELETE on audit records. Existing Eloquent mutation guards remain. No old entries are rewritten. The local migration is applied.

New entries capture the actor's name at action time. Marks events additionally capture student name/admission number, subject, exam, class and section. This makes entries understandable after later renames. Legacy entries display `Name not recorded`; the viewer does not invent historical names from live records. Full before/after values keep IDs for precise record identification.

HTTP requests capture IP and user agent (bounded to 4,000 characters); console actions have no HTTP metadata. Nested password/token/secret fields remain redacted. Output is escaped, including user agents and saved remarks. Names and admission numbers are visible only to school users granted the existing `audit.view` permission.

Both GET routes use authentication, active-school context and `audit.view`. School-scoped model binding rejects foreign record IDs. Filter choices are also school-scoped. There are no edit/delete/export endpoints or new permission grants. Database append-only triggers cover ordinary SQL updates/deletes as well as query-builder operations; privileged schema administration is outside this application guarantee.

## Files

- `app/Services/AuditLogger.php`: actor/marks context, HTTP metadata, existing secret redaction.
- `app/Services/UserAdministrationService.php`: explicit enable/disable events in the existing transaction.
- `app/Models/AuditLog.php`: JSON context casting, existing school scope and mutation guards.
- `app/Http/Controllers/AuditLogController.php`: validated filters, pagination and before/after details.
- `resources/views/administration/audit-logs.blade.php` and `audit-log.blade.php`: responsive history and details.
- `routes/web.php`: index and read-only detail routes.
- New migration, `tests/Feature/AuditLoggingTest.php`, README and preview screenshots.
- `tests/Feature/EmployeeTest.php`: filter assertions now inspect returned record IDs instead of matching very short employee codes anywhere in the HTML, where random CSRF tokens could cause false failures.

## Verification

Ten focused audit tests cover permissions, tenant isolation, filter combinations, pagination, inclusive dates and invalid ranges, snapshot names, legacy records, escaped JSON, redaction, HTTP metadata, explicit account state events, marks changes/unlock/publication, database/model immutability and rollback on audit failure. Existing RBAC/academic tests verify role, permission and enrollment events.

Full regression passed: **512 tests, 3,128 assertions**. Vite build, Blade compilation, Pint and whitespace checks pass. Chromium passed 30 page/viewport combinations across 320, 390, 768, 1440 and 1920 pixels, with no page errors or page-level horizontal overflow. Checks included list pagination, filtering/empty states, details, legacy records and mobile sidebar controls. Fixtures were rendered in a transaction against `school_erp_testing` and rolled back; no fictitious audit entries were added to `school_erp`.

Previews: [desktop list](previews/audit-index-1440.png), [mobile list](previews/audit-index-390.png), [desktop details](previews/audit-detail-1440.png), [mobile details](previews/audit-detail-390.png).

Phase 26 is the final numbered phase in the supplied plan. Follow-up work is integrated acceptance and the plan's cross-cutting indexing, security and deployment review; the previously noted MySQL server upgrade remains separate maintenance.
