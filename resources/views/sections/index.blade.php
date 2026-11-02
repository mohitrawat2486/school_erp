<x-app-layout title="Sections" :full-width="true">
@php($search = data_get($filters, 'tables.page.search', $filters['q'] ?? ''))
<div class="classes-workspace sections-workspace">
    <header class="classes-page-header"><div><p class="student-form-eyebrow">Academics</p><h1>Sections</h1><p>Create and manage sections for each class. Keep class teachers and section details in one place.</p></div><div class="class-directory-actions">
        <a href="{{ route('sections.export', $filters) }}" class="class-help-trigger" data-list-sync="sections-export"><x-icon name="download" /><span>Export</span></a>
        <button type="button" class="class-help-trigger" data-class-help-open aria-haspopup="dialog" aria-controls="section-help-dialog" hidden><x-icon name="info" /><span>How it works</span></button>
        @can('create', \App\Models\Section::class)<a href="{{ route('sections.create', $selectedClass && $selectedClass->status === \App\Enums\ClassStatus::Active ? ['class_id' => $selectedClass->id] : []) }}" class="classes-add-button button-primary" data-list-sync="sections-add"><x-icon name="plus" /><span>Add section</span></a>@endcan
    </div></header>
    <x-admin-feedback />
    <div class="classes-stats" data-list-sync="sections-stats" aria-label="Section overview">
        <div class="classes-stat"><span class="classes-stat-icon is-blue"><x-icon name="sections" /></span><div><strong>{{ number_format($totals['sections']) }}</strong><span>Total sections</span></div></div>
        <div class="classes-stat"><span class="classes-stat-icon is-green"><x-icon name="check" /></span><div><strong>{{ number_format($totals['active']) }}</strong><span>Active sections</span></div></div>
        <div class="classes-stat"><span class="classes-stat-icon is-orange"><x-icon name="pause" /></span><div><strong>{{ number_format($totals['inactive']) }}</strong><span>Inactive sections</span></div></div>
        @if($canTeachers)<div class="classes-stat"><span class="classes-stat-icon is-purple"><x-icon name="teacher" /></span><div><strong>{{ $totals['pending'] === null ? '—' : number_format($totals['pending']) }}</strong><span>Sections without class teacher</span><small>Active sections · {{ $year?->name ?? 'Choose an academic year' }}</small></div></div>@endif
    </div>
    @include('sections.partials.help-dialog')
    <form method="GET" action="{{ route('sections.index') }}" class="classes-filters list-toolbar" data-list-filters>
        <div class="classes-search"><label for="q">Search</label><div><x-icon name="search" /><input id="q" name="q" value="{{ $search }}" maxlength="100" placeholder="Section, class, reference{{ $canTeachers ? ' or teacher' : '' }}"></div></div>
        @if($canTeachers)<div><label for="academic_year_id">Academic year</label><select id="academic_year_id" name="academic_year_id"><option value="">{{ $year ? 'Current year' : 'Choose a year' }}</option>@foreach($years as $academicYear)<option value="{{ $academicYear->id }}" @selected($year?->id === $academicYear->id)>{{ $academicYear->name }}{{ $academicYear->is_current ? ' · Current' : '' }}</option>@endforeach</select></div>@endif
        <div><label for="class_id">Class</label><select id="class_id" name="class_id"><option value="">All classes</option>@foreach($classOptions as $option)<option value="{{ $option->id }}" @selected((string)($filters['class_id'] ?? '') === (string)$option->id)>{{ $option->name }}{{ $option->status->value === 'INACTIVE' ? ' · Inactive' : '' }}</option>@endforeach</select></div>
        <div><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option><option value="ACTIVE" @selected(($filters['status'] ?? '') === 'ACTIVE')>Active sections</option>@if($canTeachers && $year)<option value="PENDING" @selected(($filters['status'] ?? '') === 'PENDING')>Pending assignment</option>@endif<option value="INACTIVE" @selected(($filters['status'] ?? '') === 'INACTIVE')>Inactive sections</option></select></div>
        <div class="classes-filter-actions"><button type="submit" class="classes-add-button button-primary"><x-icon name="search" /><span>Filter</span></button><a href="{{ route('sections.index') }}" class="class-help-trigger">Reset</a></div>
    </form>
    <div class="classes-context" data-list-sync="sections-context"><span><x-icon name="info" />@if($canTeachers){{ $year ? 'Class teachers are shown for '.$year->name.'. Pending means an active section needs a class teacher.' : 'Choose an academic year to see class teacher assignments.' }} @endif Overview cards count all sections; filters apply to the list.</span></div>
    <div class="classes-directory">
        <x-data-table :records="$sections" title="Class sections" icon="sections" data-external-search>
            <table class="classes-directory-table"><thead><tr><th>#</th><th>Class</th><th>Section</th><th>Section reference</th><th>Capacity</th>@if($canTeachers)<th>Class teacher</th>@endif<th>Status</th><th>Actions</th></tr></thead><tbody>
            @forelse($rows as $row)
                @php($section = $row['section'])
                <tr class="{{ $row['status'] === 'PENDING' ? 'section-row-pending' : '' }}">
                    <td class="classes-row-number">{{ $sections->firstItem() + $loop->index }}</td>
                    <td class="classes-row-name">{{ $section->schoolClass->name }}@if($section->schoolClass->status->value === 'INACTIVE')<span class="section-parent-inactive">Class inactive</span>@endif</td>
                    <td><span class="classes-section-name">{{ $section->name }}</span></td>
                    <td><span class="classes-reference">{{ $row['reference'] }}</span></td><td>{{ $section->capacity ?? 'Not set' }}</td>
                    @if($canTeachers)<td>@if(!$year)<span class="classes-muted">No year selected</span>@elseif($row['teacher'])<span class="classes-teacher-name"><x-icon name="teacher" />{{ $row['teacher']->employee->fullName() }}</span>@else<span class="classes-unassigned"><x-icon name="info" />Not assigned</span>@endif</td>@endif
                    <td><x-status-badge :value="$row['status']" :label="ucfirst(strtolower($row['status']))" /></td>
                    <td><div class="classes-row-actions"><x-action-link :href="route('sections.show', $section)" label="View" :icon-only="true" icon="eye" :aria-label="'View '.$section->schoolClass->name.' section '.$section->name" />
                        @can('update', $section)<x-action-link :href="route('sections.edit', $section)" label="Edit" :icon-only="true" icon="pencil" :aria-label="'Edit '.$section->schoolClass->name.' section '.$section->name" />@endcan
                        @if($row['status'] === 'PENDING' && $year && in_array($year->status->value, ['ACTIVE', 'DRAFT'], true))
                            @can('create', \App\Models\TeacherAssignment::class)<x-action-link :href="route('assignments.create', ['academic_year_id' => $year->id, 'class_id' => $section->class_id, 'section_id' => $section->id, 'assignment_type' => 'CLASS_TEACHER', 'scope' => 'SECTION'])" label="Assign" icon="assignment" :primary="true" :aria-label="'Assign class teacher to '.$section->schoolClass->name.' section '.$section->name" />@endcan
                        @endif
                    </div></td>
                </tr>
            @empty
                <tr><td colspan="{{ $canTeachers ? 8 : 7 }}">No sections match your filters.</td></tr>
            @endforelse
            </tbody></table>
        </x-data-table>
    </div>
</div>
</x-app-layout>
