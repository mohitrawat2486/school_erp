# Class 1A and 1B sample data

Run `php artisan school:sample-class-one USER_ID` with an authorized operator from the intended school. The local dataset was created for the existing school, using operator ID 1.

Includes one Class 1 with A/B sections, ten students per section, twenty primary guardians, ten employees/teacher profiles, ten subjects and class/year allocations, twenty subject-teacher assignments plus two class teachers, a six-band grading scheme, one exam term and assessment, two hundred submitted/verified/locked marks, and twenty published student results with two hundred subject snapshots.

Student admission numbers start `1A-` / `1B-`; employee numbers start `T`, subject codes `S`, the exam is **Assessment - Class 1**, and the grading scheme is **Primary Grades**. Names and assessment values are fictitious; emails use `example.test` and guardian numbers use the reserved fictional 202-555-01xx range. Teacher profiles do not create login accounts.

With an empty academic setup, this creates and activates **2026-27**. If an active current year already exists, it uses that year. Existing school branding/accounts are retained. Creation refuses to modify a pre-existing Class 1. A completion audit marker makes repeat runs return the existing dataset rather than add duplicates. All operations are in one transaction and use the application's normal services, authorization, audits and publication protections.

There are **17 passing and 3 failing results**, including one absent subject. These cover grade/rank displays, report cards and Excel/PDF exports. Roll numbers 1–10 are separate within each section. The ten subjects are English, Hindi, Mathematics, Environmental Studies, Computer Basics, General Knowledge, Art and Craft, Physical Education, Moral Science and Music. The last four use practical-only marks; the others use theory-only marks.

Browse **Academics → Classes / Sections / Subjects**, **Employees → Teachers**, **Students → Student List / Enrollment / Guardians**, and **Results → Class-wise Results**. Choose the sample year, sample assessment, Class 1, then A or B. Results are already published and can be printed/exported.

Verification: two focused tests / 23 assertions passed against `school_erp_testing`, including counts, sealed publication, pass/fail totals, class protection and duplicate prevention. No schema, dependencies, role grants or existing accounts were changed.

## Label cleanup

At the user’s request, “Sample” was removed from the generated editable record labels, codes, admission/employee numbers, placeholder contact names/emails and addresses. Future generation uses the cleaned names too. The local cleanup changed 65 records, with an audit entry per changed record, in one transaction. The metadata-only maintenance script is `development/maintenance/remove-class-one-sample-labels.php` and accepts an authorized operator ID. It scopes records through the original creation marker and class relationships.

Existing published result snapshots, locked marks and original audit history retain their recorded text. As a result, historical result filters/report cards still show the original published names and admission numbers. No publication, marks, grades or ranks were rewritten.
