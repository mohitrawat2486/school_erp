@props(['title' => 'Dashboard', 'fullWidth' => false])
@php
    $school = app(\App\Support\CurrentSchool::class)->get();
    $currentYear = app(\App\Support\CurrentSchool::class)->academicYear();
@endphp
<x-layout :title="$title">
    <a href="#main-content" class="skip-link">Skip to content</a>
    <div class="app-shell" data-sidebar-root data-mobile-open="false" data-desktop-collapsed="false">
        <button type="button" class="sidebar-backdrop" data-sidebar-backdrop aria-label="Close navigation" tabindex="-1" hidden></button>
        <aside id="app-sidebar" class="app-sidebar" aria-label="Main navigation" data-sidebar-panel>
            <div class="sidebar-brand-row">
                <a href="{{ route('dashboard') }}" class="sidebar-brand" aria-label="{{ $school->name }} — Dashboard" title="{{ $school->name }} — Dashboard">
                    <img src="{{ $school->logo_path ? route('school.logo') : asset('images/school-logo.png') }}" alt="{{ $school->name }} logo" class="sidebar-logo">
                    <span><span class="sidebar-brand-name">School ERP</span><span class="sidebar-brand-caption">School administration</span></span>
                </a>
                <button type="button" class="sidebar-close icon-button" data-sidebar-close aria-label="Close navigation"><x-icon name="close" /></button>
            </div>
            <nav class="sidebar-nav" aria-label="Main navigation">
                <x-sidebar-link :href="route('dashboard')" icon="dashboard" label="Dashboard" :active="request()->routeIs('dashboard')" />

                @if(auth()->user()->can('academic_year.view') || auth()->user()->can('class.view') || auth()->user()->can('section.view') || auth()->user()->can('subject.view') || auth()->user()->can('teacher_assignment.view'))
                    <p class="sidebar-group-label">Academics</p>
                @endif
                @can('academic_year.view')
                    <x-sidebar-link :href="route('academic-years.index')" icon="calendar" label="Academic Years" :active="request()->routeIs('academic-years.*')" />
                @endcan
                @can('class.view')
                    <x-sidebar-link :href="route('classes.index')" icon="classes" label="Classes" :active="request()->routeIs('classes.*')" />
                @endcan
                @can('section.view')
                    <x-sidebar-link :href="route('sections.index')" icon="sections" label="Sections" :active="request()->routeIs('sections.*')" />
                @endcan
                @can('subject.view')
                    <x-sidebar-link :href="route('subjects.index')" icon="book" label="Subjects" :active="request()->routeIs('subjects.*')" />
                    <x-sidebar-link :href="route('class-subjects.index')" icon="books" label="Class Subjects" :active="request()->routeIs('class-subjects.*')" />
                @endcan
                @can('teacher_assignment.view')
                    <x-sidebar-link :href="route('assignments.index')" icon="assignment" label="Teacher Assignments" :active="request()->routeIs('assignments.*')" />
                @endcan

                @can('student.view')
                    <p class="sidebar-group-label">Students</p>
                    <x-sidebar-link :href="route('students.index')" :active="request()->routeIs('students.*') && !request()->routeIs('students.create')" icon="users" label="Student List" />
                    <x-sidebar-link :href="route('enrollments.index')" :active="request()->routeIs('enrollments.*')" icon="classes" label="Enrollment" />
                    <x-sidebar-link :href="route('promotions.index')" :active="request()->routeIs('promotions.*')" icon="assignment" label="Promotion" />
                    <x-sidebar-link :href="route('guardians.index')" :active="request()->routeIs('guardians.*')" icon="users" label="Guardians" />
                    @can('create', \App\Models\Student::class)
                        <x-sidebar-link :href="route('students.create')" :active="request()->routeIs('students.create')" icon="plus" label="Admission" />
                    @endcan
                @endcan
                @if(auth()->user()->can('employee.view') || auth()->user()->can('teacher.view'))
                    <p class="sidebar-group-label">Employees</p>
                @endif
                @can('employee.view')
                    <x-sidebar-link :href="route('employees.index')" icon="employees" label="Employee List" :active="request()->routeIs('employees.*')" />
                @endcan
                @can('teacher.view')
                    <x-sidebar-link :href="route('teachers.index')" icon="teacher" label="Teachers" :active="request()->routeIs('teachers.*')" />
                @endcan

                @if(auth()->user()->can('exam.view') || auth()->user()->can('marks.view'))
                    <p class="sidebar-group-label">Examinations</p>
                @endif
                @can('exam.view')
                    <x-sidebar-link :href="route('exam-terms.index')" :active="request()->routeIs('exam-terms.*')" icon="calendar" label="Exam Terms" />
                    <x-sidebar-link :href="route('exams.index')" :active="request()->routeIs('exams.*')" icon="assignment" label="Exam Setup" />
                    <x-sidebar-link :href="route('exam-subjects.index')" :active="request()->routeIs('exam-subjects.*')" icon="book" label="Exam Subjects" />
                    <x-sidebar-link :href="route('grading.index')" :active="request()->routeIs('grading.*')" icon="book" label="Grading Schemes" />
                @endcan
                @can('viewAny', \App\Models\StudentMark::class)
                    <x-sidebar-link :href="route('marks-review.index')" icon="shield" label="Marks Verification" :active="request()->routeIs('marks-review.*')" />
                @endcan
                @can('marks.view')
                    <x-sidebar-link :href="route('marks.index')" icon="assignment" label="Marks Entry" :active="request()->routeIs('marks.*')" />
                @endcan
                @can('result.view')
                    <p class="sidebar-group-label">Results</p>
                    <x-sidebar-link :href="route('results.index')" :active="request()->routeIs('results.*')" icon="assignment" label="Result Approval" />
                    <x-sidebar-link :href="route('class-results.index')" :active="request()->routeIs('class-results.*')" icon="classes" label="Class-wise Results" />
                    @can('result.print')<x-sidebar-link :href="route('report-pdfs.index')" :active="request()->routeIs('report-pdfs.index','report-pdfs.show','report-pdfs.download')" icon="book" label="My PDF Downloads" />@endcan
                @endcan
                @if(auth()->user()->can('rbac.view') || auth()->user()->can('audit.view'))
                    <p class="sidebar-group-label">Administration</p>
                @endif
                @can('rbac.view')
                    <x-sidebar-link :href="route('admin.users.index')" icon="users" label="Users" :active="request()->routeIs('admin.users.*')" />
                    <x-sidebar-link :href="route('admin.roles.index')" icon="shield" label="Roles" :active="request()->routeIs('admin.roles.*')" />
                    <x-sidebar-link :href="route('admin.permissions.index')" icon="key" label="Permissions" :active="request()->routeIs('admin.permissions.*')" />
                @endcan
                @can('audit.view')
                    <x-sidebar-link :href="route('audit.index')" icon="audit" label="Audit logs" :active="request()->routeIs('audit.*')" />
                @endcan

                @can('school.view')
                    <p class="sidebar-group-label">Settings</p>
                    <x-sidebar-link :href="route('school.show')" icon="settings" label="School Details" :active="request()->routeIs('school.*')" />
                @endcan
            </nav>
            <div class="sidebar-footer">
                <button type="button" class="sidebar-link sidebar-collapse" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="true" aria-label="Collapse sidebar" title="Collapse sidebar"><x-icon name="collapse" /><span>Collapse sidebar</span></button>
            </div>
        </aside>
        <div class="app-workspace" data-sidebar-workspace>
            <header class="app-topbar">
                <div class="topbar-context">
                    <button type="button" class="sidebar-toggle icon-button" data-sidebar-toggle aria-controls="app-sidebar" aria-expanded="false" aria-label="Open navigation"><x-icon name="menu" /></button>
                    <div class="school-identity">
                        <p class="school-name" title="{{ $school->name }}">{{ $school->name }}</p>
                        <p class="school-year">{{ $currentYear?->name ?? 'No current academic year' }}</p>
                    </div>
                </div>
                <div class="topbar-account">
                    <span class="account-name" title="{{ auth()->user()->name }}">{{ auth()->user()->name }}</span>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button class="logout-button" aria-label="Sign out"><x-icon name="logout" /><span class="logout-label">Sign out</span></button>
                    </form>
                </div>
            </header>
            <main id="main-content" class="app-content {{ $fullWidth ? 'app-content--wide' : '' }}" tabindex="-1">{{ $slot }}</main>
        </div>
    </div>
    <noscript><style>.sidebar-toggle,.sidebar-close,.sidebar-footer{display:none!important}.app-sidebar{position:static;width:100%;height:auto;max-height:none;transform:none;visibility:visible}.sidebar-nav{max-height:50vh}.app-workspace{margin-left:0!important}</style></noscript>
</x-layout>
