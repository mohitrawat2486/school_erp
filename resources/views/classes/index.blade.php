<x-app-layout title="Classes" :full-width="true">
@php
    $links = \Illuminate\Support\Arr::except($filters, ['view']);
    $search = data_get($filters, 'tables.page.search', $filters['q'] ?? '');
    $columns = 5 + ($mode === 'sections' ? 1 : 0) + ($canSections && $mode === 'classes' ? 1 : 0) + ($canStudents ? 1 : 0) + ($canTeachers ? 1 : 0) + ($canSubjects ? 1 : 0) + ($canSubjects && $canTeachers && $mode === 'sections' ? 1 : 0);
@endphp
<div class="classes-workspace is-{{ $mode }}">
    <header class="classes-page-header"><div><p class="student-form-eyebrow">Academics</p><h1>Classes</h1><p>Manage classes, sections and teaching assignments for the selected academic year.</p></div><div class="class-directory-actions">
        <a href="{{ route('classes.export', $filters + ['view' => $mode]) }}" class="class-help-trigger" data-list-sync="classes-export"><x-icon name="download" /><span>Export</span></a>
        <button type="button" class="class-help-trigger" data-class-help-open aria-haspopup="dialog" aria-controls="class-help-dialog" hidden><x-icon name="info" /><span>How it works</span></button>
        @can('create', \App\Models\SchoolClass::class)
            <a href="{{ route('classes.create') }}" class="classes-add-button button-primary"><x-icon name="plus" /><span>Add class</span></a>
        @endcan
    </div></header>
    <x-admin-feedback />
    <div class="classes-stats" data-list-sync="classes-stats" aria-label="Class overview">
        <div class="classes-stat"><span class="classes-stat-icon is-blue"><x-icon name="teacher" /></span><div><strong>{{ number_format($totals['classes']) }}</strong><span>Total classes</span></div></div>
        @if($canSections)<div class="classes-stat"><span class="classes-stat-icon is-green"><x-icon name="sections" /></span><div><strong>{{ number_format($totals['sections']) }}</strong><span>Active sections</span></div></div>@endif
        @if($canStudents)<div class="classes-stat"><span class="classes-stat-icon is-orange"><x-icon name="users" /></span><div><strong>{{ $totals['students'] === null ? '—' : number_format($totals['students']) }}</strong><span>Enrolled students</span><small>{{ $year?->name ?? 'Choose an academic year' }}</small></div></div>@endif
        @if($canTeachers && $canSections)<div class="classes-stat"><span class="classes-stat-icon is-purple"><x-icon name="employees" /></span><div><strong>{{ $totals['teachers'] === null ? '—' : number_format($totals['teachers']) }}</strong><span>Class teachers</span><small>Assigned sections</small></div></div>@endif
    </div>
    @include('classes.partials.help-dialog')
    <form method="GET" action="{{ route('classes.index') }}" class="classes-filters list-toolbar" data-list-filters>
        <input type="hidden" name="view" value="{{ $mode }}">
        <div class="classes-search"><label for="q">Search</label><div><x-icon name="search" /><input id="q" name="q" value="{{ $search }}" maxlength="100" placeholder="Class{{ $canSections ? ', section' : '' }}{{ $canTeachers ? ', teacher' : '' }}{{ $canSubjects ? ' or subject' : ' or code' }}"></div></div>
        <div><label for="academic_year_id">Academic year</label><select id="academic_year_id" name="academic_year_id"><option value="">{{ $year ? 'Current year' : 'Choose a year' }}</option>@foreach($years as $academicYear)<option value="{{ $academicYear->id }}" @selected($year?->id === $academicYear->id)>{{ $academicYear->name }}{{ $academicYear->is_current ? ' · Current' : '' }}</option>@endforeach</select></div>
        <div><label for="class_id">Class</label><select id="class_id" name="class_id"><option value="">All classes</option>@foreach($classOptions as $option)<option value="{{ $option->id }}" @selected((string)($filters['class_id'] ?? '') === (string)$option->id)>{{ $option->name }}</option>@endforeach</select></div>
        @if($canSubjects)<div><label for="subject_id">Subject</label><select id="subject_id" name="subject_id"><option value="">All subjects</option>@foreach($subjectOptions as $option)<option value="{{ $option->id }}" @selected((string)($filters['subject_id'] ?? '') === (string)$option->id)>{{ $option->name }}</option>@endforeach</select></div>@endif
        <div><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option><option value="ACTIVE" @selected(($filters['status'] ?? '') === 'ACTIVE')>Active{{ $canTeachers && $year ? ' · Assigned' : '' }}</option>@if($canTeachers && $year)<option value="PENDING" @selected(($filters['status'] ?? '') === 'PENDING')>Pending assignment</option>@endif<option value="INACTIVE" @selected(($filters['status'] ?? '') === 'INACTIVE')>Inactive</option></select></div>
        <div class="classes-filter-actions"><button type="submit" class="classes-add-button button-primary"><x-icon name="search" /><span>Filter</span></button><a href="{{ route('classes.index', ['view' => $mode]) }}" class="class-help-trigger">Reset</a></div>
    </form>
    <div class="classes-context" data-list-sync="classes-context">@if($year)<span><x-icon name="calendar" />Viewing {{ $year->name }} · Counts and assignments use this year.</span>@else<span><x-icon name="info" />Choose an academic year to see student counts and teaching assignments.</span>@endif</div>
    <div class="classes-directory">
        <div class="classes-directory-toolbar" data-list-sync="classes-tabs">
            <nav class="classes-view-tabs" aria-label="Class list view">
                @if($canSections)<a href="{{ route('classes.index', $links + ['view' => 'sections']) }}" class="{{ $mode === 'sections' ? 'is-current' : '' }}" @if($mode === 'sections') aria-current="page" @endif><x-icon name="sections" />Sections <span>{{ $totals['sections'] }}</span></a>@endif
                <a href="{{ route('classes.index', $links + ['view' => 'classes']) }}" class="{{ $mode === 'classes' ? 'is-current' : '' }}" @if($mode === 'classes') aria-current="page" @endif><x-icon name="classes" />Classes <span>{{ $totals['classes'] }}</span></a>
            </nav>
            @if($coverage['assigned'] !== null)<div class="classes-coverage" aria-label="Section assignment coverage"><a href="{{ route('classes.index', array_replace($filters, ['view' => 'sections', 'status' => 'ACTIVE'])) }}"><x-icon name="check" /><span>Assigned ({{ $coverage['assigned'] }})</span></a><a href="{{ route('classes.index', array_replace($filters, ['view' => 'sections', 'status' => 'PENDING'])) }}"><x-icon name="info" /><span>Pending ({{ $coverage['pending'] }})</span></a></div>@endif
        </div>
        <x-data-table :records="$records" :title="$mode === 'sections' ? 'Class sections' : 'School classes'" icon="classes" :sticky-actions="true" data-external-search>
            <table class="classes-directory-table"><thead><tr><th>#</th><th>Class</th>@if($mode === 'sections')<th>Section</th>@endif<th>{{ $mode === 'sections' ? 'Section reference' : 'Class code' }}</th>@if($mode === 'classes' && $canSections)<th>Sections</th>@endif @if($canStudents)<th>Students</th>@endif @if($canTeachers)<th>Class teacher{{ $mode === 'classes' ? 's' : '' }}</th>@endif @if($canTeachers && $canSubjects && $mode === 'sections')<th>Teacher’s subjects</th>@endif @if($canSubjects)<th>Total subjects</th>@endif<th>Status</th><th>Actions</th></tr></thead><tbody>
            @forelse($rows as $row)
                @include('classes.partials.directory-row', ['number' => $records->firstItem() + $loop->index])
            @empty
                <tr><td colspan="{{ $columns }}">{{ $mode === 'sections' ? 'No active sections match your filters. Use Classes to view classes without sections or inactive classes.' : 'No classes match your filters.' }}</td></tr>
            @endforelse
            </tbody></table>
        </x-data-table>
    </div>
</div>
</x-app-layout>
