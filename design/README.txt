SCHOOL REPORT CARD MANAGEMENT - CUSTOMER DEMO V4

Open index.html.

Password for all users: demo123
Admin: admin@school.test
Class Teacher: class.teacher@school.test
Subject Teacher: subject.teacher@school.test

V4 permission decisions:
- Admin: full application access; may edit any data and correct finalized marks. Finalized corrections require a reason and audit history.
- Class Teacher: may review and correct marks only for the assigned class/section. Correction reason and audit history are required.
- Subject Teacher: may enter/edit own assigned subject marks while open; after subject finalization, the prototype assumes correction is handled by Class Teacher or Admin.
- No mark correction silently overwrites history in the intended Laravel implementation.

Report card V4:
- Branded school header and crest
- Logo watermark
- A4 print-oriented layout
- Parent/student academic identity
- Scholastic result table
- Configurable pass marks and grades
- Result summary and optional rank
- Attendance
- Co-scholastic assessment
- Strengths and improvement areas
- Class teacher remark
- Promotion recommendation
- Grading scale
- Three signature areas

Static HTML prototype for customer workflow and visual approval.


V5 decisions:
- Marks are revisioned. Original subject-teacher entry and class-teacher/admin correction both remain visible.
- A current/effective mark is resolved from the latest approved revision; prior entries are immutable history.
- Import Marks prototype added for Excel and structured Word tables.
- Import uses upload -> column mapping -> validation preview -> confirm.
- Existing marks are never silently overwritten during import.

V6 fixes:
- Primary action/navigation color changed from green to crest-ribbon maroon.
- Class Teacher navigation no longer reuses Admin attendance, remarks, report-card or tabulation routes.
- Dedicated Class Teacher pages added for Attendance, Remarks, Report Cards, Tabulation and Import Marks.
- Class Teacher scope remains Class 1 / Section A in prototype.

V7:
- Added configurable Co-Scholastic Assessment feature.
- Admin can enable/disable the feature and configure assessment areas.
- Class Teacher has a dedicated co-scholastic entry grid for the assigned class.
- Co-scholastic grades are descriptive and excluded from academic totals/percentage/pass-fail in MVP.

V7.1:
- Fixed Co-Scholastic Setup row alignment.
- Grade Scale controls now use equal fixed widths.
- Active badges now use equal fixed widths and centered labels.
- Assessment area name, grade scale and status columns align consistently.
- Add Assessment Area input/button layout fixed and made responsive.

FINAL PREMIUM REVISION:
- Added consistent navigation icons and dashboard metric icons for all three roles.
- Fixed Admin/Class Teacher/Subject Teacher prototype session navigation; moving between menu pages no longer logs the user out.
- Logout is consistently visible in the top bar on every authenticated role page.
- Standardized CTA height, padding, minimum width, radius and typography.
- Redesigned Admin and Class Teacher Report Card workspaces with student selector, Single Student / Whole Class switch, Print, Download PDF and Publish/Review controls.
