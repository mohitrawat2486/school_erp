<x-app-layout title="Subjects" :full-width="true">
@php($search = data_get($filters, 'tables.page.search', $filters['q'] ?? ''))
<div class="classes-workspace subjects-workspace">
    <header class="classes-page-header"><div><p class="student-form-eyebrow">Academics</p><h1>Subjects</h1><p>Manage your subject catalog and the classes where each subject is taught.</p></div><div class="class-directory-actions">
        <a href="{{ route('subjects.export', $filters) }}" class="class-help-trigger" data-list-sync="subjects-export"><x-icon name="download" /><span>Export</span></a>
        <button type="button" class="class-help-trigger" data-class-help-open aria-haspopup="dialog" aria-controls="subjects-help-dialog" hidden><x-icon name="info" /><span>How it works</span></button>
        @can('create', \App\Models\Subject::class)<a href="{{ route('subjects.create', ['catalog' => $catalog]) }}" class="classes-add-button button-primary" data-list-sync="subject-catalog-add"><x-icon name="plus" /><span>Add subject</span></a>@endcan
    </div></header>
    <x-admin-feedback />
    <div class="classes-stats" data-list-sync="subjects-stats" aria-label="Subject overview">
        <div class="classes-stat"><span class="classes-stat-icon is-blue"><x-icon name="book" /></span><div><strong>{{ number_format($totals['subjects']) }}</strong><span>Total subjects</span></div></div>
        <div class="classes-stat"><span class="classes-stat-icon is-green"><x-icon name="check" /></span><div><strong>{{ number_format($totals['active']) }}</strong><span>Active subjects</span></div></div>
        <div class="classes-stat"><span class="classes-stat-icon is-orange"><x-icon name="pause" /></span><div><strong>{{ number_format($totals['inactive']) }}</strong><span>Inactive subjects</span></div></div>
        <div class="classes-stat"><span class="classes-stat-icon is-purple"><x-icon name="classes" /></span><div><strong>{{ $totals['unassigned'] === null ? '—' : number_format($totals['unassigned']) }}</strong><span>Subjects without classes</span><small>Active subjects · {{ $year?->name ?? 'No active current year' }}</small></div></div>
    </div>
    <x-directory-help id="subjects-help-dialog" title="Subjects &amp; class use" description="A quick guide to managing your subject catalog." summary="How subjects and class assignments work">
        <section><span class="class-help-icon"><x-icon name="book" /></span><div><h3>Keep one subject catalog</h3><p>Create each subject once with its name, code and type: <strong>Core</strong> or <strong>Optional</strong>. Reuse that subject across classes.</p></div></section>
        <section><span class="class-help-icon"><x-icon name="classes" /></span><div><h3>Choose where a subject is taught</h3><p>Choose <strong>Classes</strong> beside a subject to see its class assignments. Use <strong>Manage classes</strong> to select classes together, then save. <strong>By class</strong> shows assignments from the class perspective.</p><p>Class counts use active assignments in the active current academic year. @if($year)<strong>Not assigned</strong> means the subject has no class assignments for that year.@else Set an active current year before reviewing class use.@endif</p></div></section>
        <section><span class="class-help-icon"><x-icon name="shield" /></span><div><h3>Keep existing records safe</h3><p>Subject availability is separate from its class assignments. Inactive subjects remain available for reference. Class assignments used by teachers or exams are protected from removal.</p></div></section>
        <aside class="class-help-example"><h3>For example</h3><p>Create <strong>Mathematics</strong> once, then assign it to <strong>Class 1</strong> and <strong>Class 2</strong>. Its class count becomes <strong>2 classes</strong> for that academic year.</p></aside>
        <p class="class-help-note"><x-icon name="search" /><span>Search by subject name or code, then filter by type or status. Overview cards count the whole catalog; filters apply to the list. Export downloads all matching rows.</span></p>
    </x-directory-help>
    <x-subject-workspace-tabs :catalog="$catalog" :year="$year" />
    <form method="GET" action="{{ route('subjects.index') }}" class="classes-filters list-toolbar" data-list-filters>
        <div class="classes-search"><label for="q">Search</label><div><x-icon name="search" /><input id="q" name="q" value="{{ $search }}" maxlength="100" placeholder="Subject name or code"></div></div>
        <div><label for="type">Type</label><select id="type" name="type"><option value="">All types</option>@foreach(\App\Enums\SubjectType::cases() as $type)<option value="{{ $type->value }}" @selected(($filters['type'] ?? '') === $type->value)>{{ ucfirst(strtolower($type->value)) }}</option>@endforeach</select></div>
        <div><label for="status">Status</label><select id="status" name="status"><option value="">All statuses</option>@foreach(\App\Enums\SubjectStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status'] ?? '') === $status->value)>{{ ucfirst(strtolower($status->value)) }}</option>@endforeach</select></div>
        <div class="classes-filter-actions"><button type="submit" class="classes-add-button button-primary"><x-icon name="search" /><span>Filter</span></button><a href="{{ route('subjects.index') }}" class="class-help-trigger">Reset</a></div>
    </form>
    <div class="classes-context" data-list-sync="subjects-context"><span><x-icon name="calendar" />@if($year)Class counts show active assignments for {{ $year->name }}.@else Set an active current academic year to see class counts.@endif Overview cards count all subjects.</span></div>
    <div class="classes-directory">
        <x-data-table :records="$subjects" title="Subject catalog" icon="book" data-external-search>
            <table class="classes-directory-table"><thead><tr><th>#</th><th>Subject</th><th>Code</th><th>Type</th><th>Classes</th><th>Status</th><th>Actions</th></tr></thead><tbody>
            @forelse($subjects as $subject)
                <tr><td class="classes-row-number">{{ $subjects->firstItem() + $loop->index }}</td><td class="classes-row-name">{{ $subject->name }}</td><td><span class="classes-reference">{{ $subject->code }}</span></td><td><span class="directory-type">{{ ucfirst(strtolower($subject->type->value)) }}</span></td><td>@if($year)<span class="assignment-count {{ $subject->current_classes_count ? '' : 'is-empty' }}">{{ $subject->current_classes_count ? $subject->current_classes_count.($subject->current_classes_count === 1 ? ' class' : ' classes') : 'Not assigned' }}</span>@else<span class="classes-muted">No current year</span>@endif</td><td><x-status-badge :value="$subject->status->value" :label="ucfirst(strtolower($subject->status->value))" /></td>
                <td><div class="classes-row-actions"><x-action-link :href="route('subjects.show', ['subject' => $subject->id, 'catalog' => $catalog])" label="View" :icon-only="true" icon="eye" :aria-label="'View '.$subject->name" />@can('update', $subject)<x-action-link :href="route('subjects.edit', ['subject' => $subject->id, 'catalog' => $catalog])" label="Edit" :icon-only="true" icon="pencil" :aria-label="'Edit '.$subject->name" />@endcan<x-action-link :href="route('class-subjects.index', ['subject_id' => $subject->id, ...($year ? ['academic_year_id' => $year->id, 'status' => 'ACTIVE'] : []), 'catalog' => $catalog])" label="Classes" icon="classes" :primary="true" :aria-label="'View classes for '.$subject->name" /></div></td></tr>
            @empty
                <tr><td colspan="7">No subjects match your filters.</td></tr>
            @endforelse
            </tbody></table>
        </x-data-table>
    </div>
</div>
</x-app-layout>
