<x-app-layout title="Students" :full-width="true">
@php($search = data_get($filters, 'tables.page.search', $filters['q'] ?? ''))
<div class="classes-workspace student-directory-workspace">
    <header class="classes-page-header"><div><p class="student-form-eyebrow">Student management</p><h1>Students</h1><p>Manage student records, guardians and class placements in one place.</p></div><div class="class-directory-actions">
        <a href="{{ route('students.export', $filters) }}" class="class-help-trigger" data-list-sync="students-export"><x-icon name="download" /><span>Export</span></a>
        <button type="button" class="class-help-trigger" data-class-help-open aria-haspopup="dialog" aria-controls="students-help-dialog" hidden><x-icon name="info" /><span>How it works</span></button>
        @if($progressionCatalog)<button type="button" class="progression-launch" data-progression-open><x-icon name="assignment" /><span>Move to next academic year</span></button>@endif
        @if(auth()->user()->can('student.import') && auth()->user()->can('create', \App\Models\Student::class))<a href="{{ route('students.import.index') }}" class="class-help-trigger"><x-icon name="download" /><span>Import students</span></a>@endif
        @can('create', \App\Models\Student::class)<a href="{{ route('students.create') }}" class="classes-add-button button-primary"><x-icon name="plus" /><span>Add student</span></a>@endcan
    </div></header>
    <x-admin-feedback />
    <div class="classes-stats" data-list-sync="students-stats" aria-label="Student overview">
        <div class="classes-stat"><span class="classes-stat-icon is-blue"><x-icon name="users" /></span><div><strong>{{ number_format($totals['students']) }}</strong><span>Total students</span></div></div>
        <div class="classes-stat"><span class="classes-stat-icon is-green"><x-icon name="check" /></span><div><strong>{{ number_format($totals['active']) }}</strong><span>Active students</span></div></div>
        <div class="classes-stat"><span class="classes-stat-icon is-orange"><x-icon name="pause" /></span><div><strong>{{ number_format($totals['inactive']) }}</strong><span>Inactive students</span></div></div>
        <div class="classes-stat"><span class="classes-stat-icon is-purple"><x-icon name="classes" /></span><div><strong>{{ $totals['enrolled'] === null ? '—' : number_format($totals['enrolled']) }}</strong><span>Enrolled this year</span><small>{{ $year?->name ?? 'No current academic year' }}</small></div></div>
    </div>
    <x-directory-help id="students-help-dialog" title="Students &amp; class placement" description="A quick guide to managing student records." summary="How student records and enrollments work">
        <section><span class="class-help-icon"><x-icon name="users" /></span><div><h3>Keep student details together</h3><p>Use the <strong>add button</strong> to create a student record. Add personal details and the required Aadhaar number. With the appropriate access, you can add up to <strong>three guardians</strong> and class placement in the same form.</p><p>Choose <strong>Edit</strong> to update a record. Changes to guardians and enrollment save with the student form.</p></div></section>
        <section><span class="class-help-icon"><x-icon name="classes" /></span><div><h3>Find students by class and section</h3><p>Choose a <strong>Class</strong>, then a <strong>Section</strong>. These filters use active enrollments in the current academic year. <strong>Not enrolled</strong> means there is no active placement for that year.</p><p>Student status and enrollment status are separate: an active student record may still need class placement. Overview cards count all students; filters apply to the list.</p></div></section>
        <section><span class="class-help-icon"><x-icon name="pencil" /></span><div><h3>Manage changes from the student form</h3><p>With enrollment access, use the edit form to place a student or record a <strong>withdrawal or transfer</strong>. Earlier enrollments and results remain in history.</p></div></section>
        @if($progressionCatalog)<section><span class="class-help-icon"><x-icon name="calendar" /></span><div><h3>Move students at the end of the year</h3><p>If available, choose <strong>Move to next academic year</strong> to select students, choose Promote or Repeat, review the destination and confirm once. This keeps their previous year’s records.</p></div></section>@endif
        <aside class="class-help-example"><h3>For example</h3><p>To find students in <strong>Class 1 · Section A</strong>, select Class 1 first, then Section A. Choose <strong>View</strong> for a student’s profile or <strong>Edit</strong> to make changes.</p></aside>
        <p class="class-help-note"><x-icon name="download" /><span>Export downloads every student matching your filters, with current class placement. Aadhaar numbers, guardian contacts and private profile details are excluded.</span></p>
    </x-directory-help>
    <form data-student-list-filters data-list-filters method="GET" action="{{ route('students.index') }}" class="classes-filters list-toolbar">
        <div class="classes-search"><label for="q">Search</label><div><x-icon name="search" /><input id="q" name="q" value="{{ $search }}" maxlength="100" placeholder="Student name or admission number"></div></div>
        <div><label for="class_id">Class</label><select id="class_id" name="class_id" aria-describedby="class-filter-help"><option value="">All classes</option>@foreach($classes as $schoolClass)<option value="{{ $schoolClass->id }}" data-sections="{{ json_encode($schoolClass->sections->map(fn ($section) => $section->only(['id', 'name']))->values()) }}" @selected((string)($filters['class_id'] ?? '') === (string)$schoolClass->id)>{{ $schoolClass->name }}{{ $schoolClass->status->value === 'INACTIVE' ? ' (inactive)' : '' }}</option>@endforeach</select></div>
        <div><label for="section_id">Section</label><select id="section_id" name="section_id" aria-describedby="class-filter-help" @disabled(empty($filters['class_id']) || $sections->isEmpty())><option value="">{{ empty($filters['class_id']) ? 'Choose a class first' : ($sections->isEmpty() ? 'No active sections' : 'All sections') }}</option>@foreach($sections as $section)<option value="{{ $section->id }}" @selected((string)($filters['section_id'] ?? '') === (string)$section->id)>{{ $section->name }}</option>@endforeach</select></div>
        <div><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option>@foreach(\App\Enums\StudentStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ ucfirst(strtolower($status->value)) }}</option>@endforeach</select></div>
        <div class="classes-filter-actions"><button type="submit" class="classes-add-button button-primary"><x-icon name="search" /><span>Filter</span></button><a href="{{ route('students.index') }}" class="class-help-trigger">Reset</a></div>
    </form>
    <div id="class-filter-help" class="classes-context" data-list-sync="students-context"><span><x-icon name="calendar" />@if($year)Class and section filters show active enrollments for {{ $year->name }}.@else No current academic year is set. Class and section filtering will show no students until a current year is selected in Academic Years.@endif Overview cards count all students.</span></div>
    <div class="classes-directory">
        <x-data-table :records="$students" title="Student directory" icon="users" data-external-search>
            <table class="classes-directory-table"><thead><tr><th>#</th><th>Admission number</th><th>Student</th><th>Class</th><th>Section</th><th>Roll number</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            @forelse($students as $student)
                @php($placement = $student->enrollments->first())
                <tr><td class="classes-row-number">{{ $students->firstItem() + $loop->index }}</td><td><span class="classes-reference">{{ $student->admission_number }}</span></td><td class="classes-row-name">{{ $student->fullName() }}</td><td>@if($placement){{ $placement->schoolClass->name }}@else<span class="classes-muted">{{ $year ? 'Not enrolled' : 'No year selected' }}</span>@endif</td><td><span class="classes-section-name">{{ $placement?->section?->name ?? '—' }}</span></td><td>{{ $placement?->roll_number ?? '—' }}</td><td><x-status-badge :value="$student->status->value" :label="ucfirst(strtolower($student->status->value))" /></td>
                <td><div class="classes-row-actions"><x-action-link :href="route('students.show', $student)" label="View" icon="eye" :aria-label="'View '.$student->fullName()" />@can('update', $student)<x-action-link :href="route('students.edit', $student)" label="Edit" icon="pencil" :aria-label="'Edit '.$student->fullName()" />@endcan</div></td></tr>
            @empty
                <tr><td colspan="8">No students match your filters.</td></tr>
            @endforelse
            </tbody></table>
        </x-data-table>
    </div>
    @if($progressionCatalog)@include('students.partials.progression-dialog')@endif
</div>
</x-app-layout>
