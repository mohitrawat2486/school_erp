You are acting as a Principal Software Architect and Senior Laravel Engineer.

We are building a School ERP application.

## Technology Stack

Use:

- Laravel
- PHP
- MySQL
- Blade
- Tailwind CSS
- Alpine.js where small frontend interactions are needed
- Livewire only where it provides clear value, such as marks entry, dynamic filters, or large interactive forms
- Laravel Policies/Gates for authorization
- Laravel Queues for heavy background operations
- Redis may be introduced later for queues/cache
- Laravel Excel for Excel import/export
- DomPDF initially for PDF/report-card generation
- PHPUnit/Pest for automated testing

The application is initially for ONE SCHOOL, but the database and application architecture must be designed so that multiple schools can be supported later.

Therefore, use `school_id` in all appropriate business tables.

Do NOT implement microservices.

Use a clean modular monolith architecture.

---

# GENERAL DEVELOPMENT RULES

Before writing any code:

1. Inspect the existing Laravel project.
2. Inspect:
   - migrations
   - models
   - controllers
   - services
   - routes
   - Blade views
   - middleware
   - policies
   - existing authentication
   - existing RBAC
   - composer packages
   - frontend dependencies
3. Reuse existing code and conventions.
4. Do NOT duplicate existing tables, models, services, helpers, or functionality.
5. Do NOT introduce another framework.
6. Do NOT unnecessarily install packages.
7. Follow existing naming conventions.
8. Follow existing folder/module structure.
9. Keep controllers thin.
10. Put business logic inside Services/Actions.
11. Use Form Request classes for validation.
12. Use Laravel Policies/Gates for authorization.
13. Use DB transactions when multiple related records must be created/updated together.
14. Add indexes and unique constraints where appropriate.
15. Use foreign-key constraints.
16. Avoid hard-coded IDs.
17. Avoid hard-coded academic years.
18. Avoid hard-coded grading rules.
19. Avoid hard-coded role IDs.
20. Use enums/constants for controlled statuses where appropriate.
21. Do not physically delete important academic records.
22. Prefer status/deactivation/soft delete where appropriate.
23. Every sensitive change should eventually support auditing.
24. Keep scalability and maintainability in mind.

IMPORTANT:

Do NOT attempt to build the entire ERP in one step.

Complete ONE PHASE at a time.

After each phase:

- run migrations if appropriate
- run tests
- run lint/format checks if configured
- check for errors
- review database relationships
- summarize files changed
- summarize database changes
- explain how to test the feature manually
- list any assumptions
- list anything pending

Do not move to the next phase until the current phase is structurally complete.

---

# TARGET APPLICATION MODULES

The initial ERP should contain:

1. School Details
2. Academic Years
3. RBAC
4. Employees
5. Teachers
6. Classes
7. Sections
8. Subjects
9. Teacher Assignments
10. Students
11. Guardians
12. Student Enrollment
13. Student Promotion
14. Examinations
15. Exam Subjects
16. Marks Entry
17. Marks Verification
18. Grading
19. Result Calculation
20. Result Approval
21. Result Publication
22. Student Report Card
23. Class-wise Result Printing
24. Excel Export
25. Audit Logs

Do NOT implement these yet:

- Fees
- Attendance
- Transport
- Library
- Payroll
- Timetable
- Homework
- Hostel
- Inventory
- Mobile app
- WhatsApp integration
- SMS integration

These can be added later.

---

# PROPOSED DOMAIN STRUCTURE

The conceptual relationship should be:

School
│
├── Academic Year
│
├── Classes
│   ├── Sections
│   └── Subjects
│
├── Employees
│   └── Teachers
│
├── Students
│   ├── Guardians
│   └── Enrollments
│
├── Users
│   └── Roles
│       └── Permissions
│
└── Examinations
    ├── Exam Subjects
    ├── Student Marks
    ├── Results
    └── Report Cards

---

# PHASE 0 — PROJECT ANALYSIS

Do NOT change anything yet.

Analyze the project and report:

1. Laravel version
2. PHP version
3. MySQL configuration
4. Authentication implementation
5. Existing user table
6. Existing RBAC implementation
7. Existing school/student/employee tables
8. Existing Tailwind configuration
9. Existing Blade layouts
10. Existing Livewire/Alpine usage
11. Existing testing framework
12. Existing PDF libraries
13. Existing Excel libraries
14. Existing queue configuration
15. Existing project folder conventions

Also identify potential conflicts with the proposed architecture.

Then propose the exact implementation plan based on the CURRENT repository.

Stop after this analysis.

---

# PHASE 1 — SCHOOL MODULE

Implement School Details.

Suggested fields:

schools

- id
- unique_id or code
- name
- short_name
- logo
- affiliation_number
- board
- email
- phone
- alternate_phone
- address_line_1
- address_line_2
- city
- state
- country
- postal_code
- timezone
- status
- created_at
- updated_at

Default timezone:

Asia/Kolkata

Create:

- migration
- model
- validation
- service/action
- controller
- routes
- policy
- Blade screens
- tests

Screens:

Settings
→ School Details

Allow authorized administrators to:

- view school details
- update school details
- upload logo

Do not create multiple schools in the UI yet.

Database should remain multi-school ready.

---

# PHASE 2 — ACADEMIC YEAR MODULE

Create:

academic_years

Fields:

- id
- school_id
- name
- start_date
- end_date
- is_current
- status
- created_at
- updated_at

Example:

2026-27

Statuses:

DRAFT
ACTIVE
CLOSED
ARCHIVED

Rules:

Only one academic year can be current for a school.

Do NOT hard-code the current year anywhere.

Add:

- CRUD
- activate academic year
- close academic year
- archive academic year

Add authorization.

---

# PHASE 3 — RBAC

Before implementation, inspect whether the application already has RBAC.

Reuse existing RBAC if possible.

Required design:

users
roles
permissions
user_roles
role_permissions

Suggested permissions:

school.view
school.update

academic_year.view
academic_year.create
academic_year.update
academic_year.activate

student.view
student.create
student.update
student.delete

employee.view
employee.create
employee.update

teacher.view
teacher.manage

class.view
class.manage

section.view
section.manage

subject.view
subject.manage

teacher_assignment.view
teacher_assignment.manage

exam.view
exam.create
exam.update
exam.publish

marks.view
marks.enter
marks.update
marks.submit
marks.verify
marks.lock
marks.unlock

result.view
result.generate
result.approve
result.publish
result.print

rbac.view
rbac.manage

audit.view

Suggested roles:

SUPER_ADMIN
SCHOOL_ADMIN
PRINCIPAL
TEACHER
ACCOUNTANT
RECEPTIONIST
RESULT_MANAGER

Do not check role names throughout application business logic.

Prefer permission checks.

Example:

$user->can('student.create')

RBAC must also support business-policy checks.

Example:

A teacher may have:

marks.enter

but should only enter marks for classes/sections/subjects assigned to that teacher.

Therefore authorization should be:

Permission
+
Teacher assignment
+
Business policy

---

# PHASE 4 — EMPLOYEE MODULE

Create employee management.

employees

Suggested fields:

- id
- school_id
- employee_number
- first_name
- middle_name
- last_name
- email
- phone
- alternate_phone
- gender
- date_of_birth
- joining_date
- designation
- department
- address
- photo
- status
- created_at
- updated_at

Possible employee types:

Principal
Teacher
Administrator
Accountant
Receptionist
Clerk
Librarian

Do NOT make every employee a teacher.

---

# PHASE 5 — TEACHER MODULE

Use:

teacher_profiles

Suggested fields:

- id
- employee_id
- qualification
- specialization
- experience
- status

Employee remains the primary person record.

Teacher profile stores teacher-specific information.

---

# PHASE 6 — CLASSES

Create:

classes

Fields:

- id
- school_id
- name
- code
- sort_order
- status

Examples:

Nursery
LKG
UKG
1
2
3
4
...
12

Do NOT create:

1A
1B
1C

as separate classes.

---

# PHASE 7 — SECTIONS

Create:

sections

Fields:

- id
- school_id
- class_id
- name
- capacity
- status

Example:

Class 5

A
B
C

Use appropriate unique constraints.

---

# PHASE 8 — SUBJECTS

Create:

subjects

Fields:

- id
- school_id
- code
- name
- type
- status

Example:

ENG - English
HIN - Hindi
MATH - Mathematics
SCI - Science
SST - Social Studies
COMP - Computer

Then create:

class_subjects

Fields:

- id
- school_id
- academic_year_id
- class_id
- subject_id
- theory_max_marks if applicable
- practical_max_marks if applicable
- passing_marks if applicable
- status

Do not permanently bind subjects only to a class without academic-year context.

---

# PHASE 9 — TEACHER ASSIGNMENTS

Create:

teacher_assignments

Fields:

- id
- school_id
- academic_year_id
- teacher_id
- class_id
- section_id nullable when appropriate
- subject_id nullable when appropriate
- assignment_type
- status

Assignment types:

CLASS_TEACHER
SUBJECT_TEACHER

Examples:

Teacher A
→ Class 5
→ Section A
→ Mathematics
→ SUBJECT_TEACHER

Teacher B
→ Class 5
→ Section A
→ CLASS_TEACHER

These assignments must later control marks-entry authorization.

---

# PHASE 10 — STUDENT MODULE

Create:

students

Suggested fields:

- id
- school_id
- admission_number
- first_name
- middle_name
- last_name
- gender
- date_of_birth
- admission_date
- blood_group
- photo
- address
- status
- created_at
- updated_at

IMPORTANT:

Do NOT store:

class_id
section_id
roll_number

directly as the authoritative current academic placement on the student record.

Student academic placement must come through enrollment.

Admission number should be unique within the school.

---

# PHASE 11 — GUARDIANS

Create:

guardians

Fields:

- id
- school_id
- first_name
- last_name
- phone
- alternate_phone
- email
- occupation
- address
- status

Create:

student_guardians

Fields:

- student_id
- guardian_id
- relationship
- is_primary
- is_emergency_contact

Relationships:

FATHER
MOTHER
GUARDIAN

Support multiple guardians per student.

---

# PHASE 12 — STUDENT ENROLLMENT

Create:

student_enrollments

Fields:

- id
- school_id
- student_id
- academic_year_id
- class_id
- section_id
- roll_number
- joined_at
- status
- created_at
- updated_at

Important constraint:

A student should normally have only one active enrollment for the same academic year.

Example history:

Rahul

2025-26
→ Class 4
→ Section A
→ Roll 17

2026-27
→ Class 5
→ Section B
→ Roll 12

Preserve historical enrollments.

---

# PHASE 13 — STUDENT PROMOTION

Create promotion workflow.

Example:

2026-27
Class 5-A

↓

2027-28
Class 6-A

Support:

- promote individual
- bulk promotion
- new section
- new roll number
- repeat class
- withdrawn student
- transferred student

Never overwrite historical enrollment.

Create a NEW enrollment.

---

# PHASE 14 — EXAMINATION MODULE

Create:

exam_terms

Fields:

- id
- school_id
- academic_year_id
- name
- status

Examples:

Unit Test 1
Half Yearly
Unit Test 2
Annual Examination

Create:

exams

Fields:

- id
- school_id
- academic_year_id
- exam_term_id
- class_id
- name
- start_date
- end_date
- status

Statuses:

DRAFT
OPEN
MARKS_ENTRY
VERIFICATION
APPROVED
PUBLISHED
CLOSED

---

# PHASE 15 — EXAM SUBJECTS

Create:

exam_subjects

Fields:

- id
- exam_id
- class_subject_id
- theory_max_marks
- practical_max_marks
- passing_marks
- status

Support subjects such as:

Mathematics

100 theory

or:

Computer

70 theory
30 practical

---

# PHASE 16 — MARKS ENTRY

Create:

student_marks

Fields:

- id
- school_id
- exam_id
- student_id
- subject_id
- theory_marks
- practical_marks
- total_marks
- is_absent
- remarks
- status
- entered_by
- submitted_by
- verified_by
- locked_by
- submitted_at
- verified_at
- locked_at
- created_at
- updated_at

Statuses:

DRAFT
SUBMITTED
VERIFIED
LOCKED

Unique constraint:

exam_id
student_id
subject_id

Marks entry UI should support:

Academic Year
→ Exam
→ Class
→ Section
→ Subject

Then table:

Roll No
Student
Theory
Practical
Absent
Total
Remarks

Support:

- bulk save
- save draft
- submit marks
- absent marking
- validation
- permission checks

Teacher must only see classes/sections/subjects assigned to that teacher unless elevated permissions exist.

---

# PHASE 17 — MARKS VERIFICATION

Workflow:

Teacher enters marks

↓

DRAFT

↓

SUBMITTED

↓

VERIFIED

↓

LOCKED

After LOCKED:

Teacher cannot modify marks.

Only authorized users with:

marks.unlock

can unlock.

Every unlock must later be audited.

---

# PHASE 18 — GRADING

Do NOT hard-code grading logic.

Create:

grading_schemes

Fields:

- id
- school_id
- name
- status

Create:

grade_rules

Fields:

- grading_scheme_id
- min_percentage
- max_percentage
- grade
- grade_point nullable
- remarks nullable

Example:

91-100 → A1
81-90 → A2
71-80 → B1
61-70 → B2
51-60 → C1
41-50 → C2
33-40 → D
0-32 → E

Use configuration through database.

---

# PHASE 19 — RESULT CALCULATION SERVICE

Create a dedicated service.

Example:

ResultCalculationService

Responsibilities:

calculateSubjectResult()
calculateTotal()
calculatePercentage()
calculateGrade()
calculatePassFail()
calculateRank()

Do NOT calculate result logic inside:

Controller
Blade
JavaScript

Result calculations must remain server-side.

Write extensive automated tests.

Test:

- normal marks
- absent student
- failed subject
- practical marks
- decimal handling
- maximum marks
- minimum marks
- invalid marks
- grade boundaries
- pass/fail boundaries

---

# PHASE 20 — RESULT APPROVAL

Workflow:

Marks Locked

↓

Generate Result

↓

Review

↓

Approve

↓

Publish

Do not allow publication if required marks are incomplete.

---

# PHASE 21 — RESULT SNAPSHOT

When results are published, create immutable result snapshots.

Create:

student_results

Fields:

- id
- school_id
- academic_year_id
- exam_id
- student_id
- enrollment_id
- total_max_marks
- obtained_marks
- percentage
- grade
- rank nullable
- result_status
- remarks
- published_at
- version
- created_at

Create:

student_result_subjects

Fields:

- student_result_id
- subject_id
- subject_name
- max_marks
- obtained_marks
- grade
- result_status

Purpose:

A report card printed months later must show the exact published result, even if configuration changes later.

Do NOT dynamically recreate historical published results from current configuration.

---

# PHASE 22 — CLASS-WISE RESULTS

Create filter:

Academic Year
Exam
Class
Section

Display:

Roll
Student
Total
Percentage
Grade
Result

Actions:

View
Print
Print Selected
Print Entire Class
Export Excel

---

# PHASE 23 — REPORT CARD

Create a printable A4 report card.

Include:

School Logo
School Name
Address
Academic Year
Exam Name

Student Name
Admission Number
Class
Section
Roll Number
Date of Birth

Subject results

Total Marks
Obtained Marks
Percentage
Grade
Result Status

Teacher remarks

Class Teacher signature

Principal signature

Use dedicated print CSS.

Do not depend heavily on Tailwind inside the PDF template if it causes DomPDF rendering problems.

---

# PHASE 24 — CLASS-WISE PDF

Allow:

Print Individual

Print Selected

Print Entire Class

If PDF generation becomes heavy, prepare architecture so PDF generation can later move to Laravel Queue.

Do not block HTTP requests unnecessarily for large result batches.

---

# PHASE 25 — EXCEL EXPORT

Implement:

Class Result Excel Export

Columns:

Roll Number
Admission Number
Student Name
Subject Marks
Total
Percentage
Grade
Result

Use Laravel Excel if already installed.

---

# PHASE 26 — AUDIT LOGGING

Create:

audit_logs

Fields:

- id
- school_id
- user_id
- module
- action
- entity_type
- entity_id
- old_values JSON
- new_values JSON
- ip_address
- user_agent
- created_at

Audit especially:

marks changes
marks unlock
result generation
result publication
role changes
permission changes
user disable/enable
student academic changes

Example:

USER:
Principal

ACTION:
MARK_UPDATED

STUDENT:
Rahul Sharma

SUBJECT:
Mathematics

OLD:
74

NEW:
84

---

# INDEXING REQUIREMENTS

Review indexes carefully.

Examples:

student_enrollments:

INDEX school_id
INDEX academic_year_id
INDEX class_id
INDEX section_id

UNIQUE:

school_id
academic_year_id
student_id

Students:

UNIQUE:

school_id
admission_number

Marks:

UNIQUE:

exam_id
student_id
subject_id

Users:

Use appropriate unique constraints based on current authentication requirements.

---

# DATABASE DESIGN RULES

Always consider:

- foreign keys
- indexes
- uniqueness
- historical records
- soft deletes
- auditability
- multi-school readiness
- academic-year isolation

Avoid N+1 queries.

Use eager loading where appropriate.

Paginate large datasets.

Do not load thousands of records unnecessarily.

---

# SECURITY

Implement:

Form Request validation

Authorization Policies

CSRF protection

Password hashing through Laravel

Rate limiting where appropriate

Secure file upload validation

MIME validation

File size limits

RBAC

Business authorization policies

Audit trail

HTTPS-ready configuration

Database backups

Do not trust IDs received from frontend.

Always verify school ownership.

Example:

A user belonging to School A must NEVER be able to retrieve School B data by manually changing an ID in the URL.

---

# MULTI-SCHOOL FUTURE SUPPORT

Even though only one school exists initially:

Design queries around the authenticated user's school.

Example concept:

school_id = authenticated user's school_id

Avoid:

School::first()

Avoid globally assuming ID = 1.

Use a CurrentSchool service/context if appropriate.

---

# UI GUIDELINES

Use Tailwind CSS.

Keep screens clean and responsive.

Suggested sidebar:

Dashboard

Academics
- Academic Years
- Classes
- Sections
- Subjects
- Teacher Assignments

Students
- Student List
- Admission
- Enrollment
- Promotion

Employees
- Employees
- Teachers

Examinations
- Exam Setup
- Marks Entry
- Marks Verification

Results
- Generate Results
- Approve Results
- Publish Results
- Class Results
- Report Cards

Administration
- Users
- Roles
- Permissions
- Audit Logs

Settings
- School Details

---

# CODING STYLE

Prefer:

Controller
↓
Request
↓
Service / Action
↓
Model / Repository if repository layer is already used

Example:

StudentController

StudentStoreRequest

CreateStudentAction

Student model

Avoid huge controllers.

Avoid huge models.

Avoid duplicated queries.

Avoid duplicated validation.

Avoid business rules inside Blade.

---

# TESTING REQUIREMENTS

Every critical module should have tests.

Prioritize:

RBAC
School isolation
Enrollment rules
Teacher assignment restrictions
Marks validation
Marks locking
Result calculation
Grade calculations
Result publication
Published result immutability

Examples:

Teacher assigned Class 5-A Math
→ Enter Class 5-A Math marks
→ ALLOWED

Teacher assigned Class 5-A Math
→ Enter Class 6-B Science marks
→ DENIED

Marks = 101
Max Marks = 100
→ VALIDATION ERROR

Marks locked
→ Teacher attempts edit
→ DENIED

Result published
→ Configuration changes
→ Previously published report remains unchanged

---

# DEVELOPMENT BEHAVIOR

For every phase:

First:

1. Inspect related existing code.
2. Explain what already exists.
3. Explain what needs to change.

Then implement.

After implementation provide:

### Completed

List implemented functionality.

### Files Created

List files.

### Files Modified

List files.

### Database Changes

List tables/columns/indexes.

### Routes

List important routes.

### Authorization

Explain permissions/policies.

### Tests

List tests added and their status.

### Manual Testing

Give exact steps to test from UI.

### Potential Issues

Mention anything that should be reviewed.

### Next Recommended Phase

Mention only the next phase.

Do NOT automatically implement the next phase unless explicitly requested.

---

# START NOW

Start ONLY with:

PHASE 0 — PROJECT ANALYSIS.

Inspect the current repository thoroughly.

Do NOT generate migrations or modify application code yet.

Return:

1. Current architecture
2. Existing relevant functionality
3. Existing database structure
4. Existing packages
5. Potential conflicts
6. Recommended changes
7. Exact plan for Phase 1

Then STOP.


