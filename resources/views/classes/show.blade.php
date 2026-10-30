<x-app-layout :title="$schoolClass->name" :full-width="true">
<nav class="setup-breadcrumb" aria-label="Breadcrumb"><span>Academics</span><x-icon name="chevron-right" /><a href="{{ route('classes.index') }}">Classes</a><x-icon name="chevron-right" /><span aria-current="page">{{ $schoolClass->name }}</span></nav>
<div class="setup-summary-header"><div><h1 class="setup-page-title">{{ $schoolClass->name }}</h1><p class="setup-page-description">Manage sections, subjects and teaching assignments in one place.</p></div>
@can('update', $schoolClass)<a href="{{ route('classes.edit',$schoolClass) }}" class="setup-save">Edit class</a>@endcan
</div>
<x-admin-feedback />
<div class="class-setup-form">
    <div class="class-setup-grid {{ !auth()->user()->can('section.view') ? 'class-setup-grid--single' : '' }}">
        <section class="setup-card setup-details" aria-labelledby="class-details-heading">
            <div class="setup-card-header"><div class="setup-card-heading"><span class="setup-card-icon"><x-icon name="classes" /></span><div><h2 id="class-details-heading">Class details</h2><p>The essentials for this class.</p></div></div><x-status-badge :value="$schoolClass->status->value" /></div>
            <div class="setup-card-body">
                <dl><dt class="text-sm text-slate-500">Class name</dt><dd class="mt-2 text-lg font-semibold text-slate-800">{{ $schoolClass->name }}</dd></dl>
                <div class="setup-code-field"><div class="setup-field-label"><label for="class-code">Class code</label><span><x-icon name="key" />Read-only</span></div><input id="class-code" readonly value="{{ $schoolClass->code }}" aria-describedby="class-code-help"><p id="class-code-help">Your class reference. You can select and copy it.</p></div>
            </div>
        </section>
        @can('section.view')
        <section class="setup-card" aria-labelledby="sections-heading">
            <div class="setup-card-header"><div class="setup-card-heading"><span class="setup-card-icon"><x-icon name="sections" /></span><div><h2 id="sections-heading">Sections</h2><p>The groups that make up this class.</p></div></div><span class="setup-badge setup-badge--muted">{{ $sections->count() }} sections</span></div>
            <div class="setup-card-body">
                <div class="setup-summary-sections">
                @forelse($sections as $section)
                    <a href="{{ route('sections.show', $section) }}" class="setup-summary-section"><span class="setup-section-initial">{{ $section->name }}</span><span><strong>Section {{ $section->name }}</strong><span>{{ $section->capacity ? 'Up to '.$section->capacity.' students' : 'No student limit set' }}</span></span><x-icon name="chevron-right" /></a>
                @empty
                    <p class="text-sm text-slate-500">No active sections.</p>
                @endforelse
                </div>
                <div class="mt-5 flex flex-wrap gap-4 text-sm font-semibold text-[#8b2f24]"><a href="{{ route('sections.index',['class_id'=>$schoolClass->id]) }}" class="underline">View sections</a>
                @can('create', \App\Models\Section::class)
                    @if($schoolClass->status === \App\Enums\ClassStatus::Active)<a href="{{ route('sections.create',['class_id'=>$schoolClass->id]) }}" class="underline">Add section</a>@endif
                @endcan
                </div>
            </div>
        </section>
        @endcan
    </div>
    @can('subject.view')
    <section class="setup-card" aria-labelledby="subjects-heading">
        <div class="setup-card-header"><div class="setup-card-heading"><span class="setup-card-icon"><x-icon name="books" /></span><div><h2 id="subjects-heading">Subjects{{ $year ? ' for '.$year->name : '' }}</h2><p>The subject list shared by all sections.</p></div></div><span class="setup-badge setup-badge--muted">{{ $allocations->count() }} subjects</span></div>
        <div class="setup-card-body">
            <div class="setup-subject-list">
            @forelse($allocations as $allocation)
                <div class="setup-subject-option setup-static-subject"><x-icon name="book" /><span><strong class="block text-sm">{{ $allocation->subject->name }}</strong>@if($allocation->status->value === 'INACTIVE')<span class="text-xs text-slate-500">Inactive</span>@endif</span></div>
            @empty
                <p class="text-sm text-slate-500">{{ $year ? 'No subjects added for this year yet.' : 'Set a current academic year to assign subjects.' }}</p>
            @endforelse
            </div>
            @can('create', \App\Models\ClassSubject::class)
                @if($year && $schoolClass->status === \App\Enums\ClassStatus::Active)<a href="{{ route('class-subjects.create', ['academic_year_id'=>$year->id, 'class_id'=>$schoolClass->id]) }}" class="mt-5 inline-block text-sm font-semibold text-[#8b2f24] underline">Add subjects</a>@endif
            @endcan
        </div>
    </section>
    @endcan
    @can('create', \App\Models\TeacherAssignment::class)
        @if($year && $schoolClass->status === \App\Enums\ClassStatus::Active && $sections->contains('status', \App\Enums\SectionStatus::Active))
        <div class="setup-form-footer"><p>Review class and subject teachers for your sections.</p><a href="{{ auth()->user()->can('update', $schoolClass) ? route('classes.edit', $schoolClass).'#teachers-heading' : route('assignments.create', ['academic_year_id'=>$year->id, 'class_id'=>$schoolClass->id, 'assignment_type'=>'CLASS_TEACHER', 'scope'=>'SECTION']) }}" class="setup-save">Assign teachers<x-icon name="chevron-right" /></a></div>
        @endif
    @endcan
    @can('update', $schoolClass)
    <details class="rounded-xl border border-slate-200 bg-white p-5"><summary class="cursor-pointer text-sm font-semibold text-slate-600">{{ $schoolClass->status === \App\Enums\ClassStatus::Active ? 'Deactivate class' : 'Activate class' }}</summary><p class="mt-3 text-sm text-slate-600">{{ $schoolClass->status === \App\Enums\ClassStatus::Active ? 'Deactivate this class and its active sections. Records will be retained.' : 'Mark this class active again. Reactivate sections separately if needed.' }}</p><form method="POST" action="{{ route($schoolClass->status === \App\Enums\ClassStatus::Active ? 'classes.deactivate' : 'classes.activate',$schoolClass) }}" class="mt-4">@csrf<button class="rounded-lg border border-slate-300 px-5 py-3 text-sm font-semibold">{{ $schoolClass->status === \App\Enums\ClassStatus::Active ? 'Confirm deactivation' : 'Confirm activation' }}</button></form></details>
    @endcan
</div>
</x-app-layout>
