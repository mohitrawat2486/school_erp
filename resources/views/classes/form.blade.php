<x-app-layout :title="$schoolClass->exists ? 'Edit class' : 'Create class'" :full-width="true">
<nav class="setup-breadcrumb" aria-label="Breadcrumb"><span>Academics</span><x-icon name="chevron-right" /><a href="{{ route('classes.index') }}">Classes</a><x-icon name="chevron-right" /><span aria-current="page">{{ $schoolClass->exists ? 'Edit class' : 'Create class' }}</span></nav>
<h1 class="setup-page-title">{{ $schoolClass->exists ? 'Edit class' : 'Create class' }}</h1>
<p class="setup-page-description">{{ $schoolClass->exists ? 'Update class details, sections and subjects in one place.' : 'Set up the class, its sections and subjects in one go.' }}</p>
<x-admin-feedback />
<form method="POST" action="{{ $schoolClass->exists ? route('classes.update',$schoolClass) : route('classes.store') }}" class="class-setup-form" data-class-setup data-original-name="{{ $schoolClass->name }}" data-original-code="{{ $schoolClass->code }}">
@csrf
@if($schoolClass->exists) @method('PUT')<input type="hidden" name="setup_revision" value="{{ $setupRevision }}"> @endif
<div class="class-setup-grid {{ !auth()->user()->can('create', \App\Models\Section::class) ? 'class-setup-grid--single' : '' }}">
<section class="setup-card setup-details" aria-labelledby="class-details-heading">
    <div class="setup-card-header"><div class="setup-card-heading"><span class="setup-card-icon"><x-icon name="classes" /></span><div><h2 id="class-details-heading">Class details</h2><p>The essentials for this class.</p></div></div><span class="setup-badge">Required</span></div>
    <div class="setup-card-body">
    <x-employee-field name="name" label="Class name" :value="$schoolClass->name" maxlength="100" required />
    <p class="mt-2 text-sm text-slate-500">Use the base class name, such as Class 2, Nursery or LKG. Section names are kept separately.</p>
    <div class="setup-code-field"><div class="setup-field-label"><label for="class-code">Class code</label><span><x-icon name="key" />Auto-generated</span></div><input id="class-code" data-class-code readonly aria-describedby="class-code-help" value="{{ old('code', $schoolClass->code) }}" placeholder="Generated from the class name"><span class="sr-only" data-class-code-announcement aria-live="polite"></span><p id="class-code-help">Created automatically. No need to enter or change it.</p></div>
    @error('code')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
    <div class="setup-detail-note"><x-icon name="sections" /><p><strong>One class, multiple sections.</strong> For Class 2A and 2B, enter Class 2 here and choose A and B in Sections.</p></div>
    </div>
</section>
    @can('create', \App\Models\Section::class)
    @php($savedSections = $errors->has('setup_revision') ? $sectionDefaults : old('sections', $sectionDefaults))
    <section class="setup-card setup-sections" data-setup-sections aria-labelledby="sections-heading">
        <div class="setup-card-header"><div class="setup-card-heading"><span class="setup-card-icon"><x-icon name="sections" /></span><div><h2 id="sections-heading">Sections</h2><p>Choose how this class is organised.</p></div></div><span class="setup-badge setup-badge--muted">Optional</span></div>
        <div class="setup-card-body">
        @if(!$setupEditable)<p class="mb-4 text-sm text-slate-500">Activate this class before changing sections.</p>@endif
        <fieldset class="setup-fieldset" @disabled(!$setupEditable)>
        <input type="hidden" name="sections_present" value="1">
        <p class="text-sm text-slate-500">{{ $schoolClass->exists ? 'Uncheck unused sections to remove them from this class when you save.' : 'Select the sections you need. You can always add more.' }}</p>
        <div class="setup-section-choices">
        @foreach(['A','B','C','D'] as $letter)
            <label class="setup-section-choice">
                <input type="hidden" name="sections[{{ $letter }}][enabled]" value="{{ in_array($letter, $lockedSectionNames, true) ? 1 : 0 }}">
                <input type="hidden" name="sections[{{ $letter }}][name]" value="{{ $letter }}">
                <input type="checkbox" name="sections[{{ $letter }}][enabled]" value="1" data-section-choice="{{ $letter }}" data-locked="{{ in_array($letter, $lockedSectionNames, true) ? 'true' : 'false' }}" @disabled(in_array($letter, $lockedSectionNames, true)) @checked(isset($savedSections[$letter]) || in_array($letter, $lockedSectionNames, true)) class="h-4 w-4 accent-[#8b2f24]"> <span class="font-semibold">{{ $letter }}</span>
            </label>
        @endforeach
        </div>
        <details class="setup-student-limits" @if($errors->has('sections.*.capacity')) open @endif><summary class="cursor-pointer text-sm font-semibold">Student limits — optional</summary><p class="mt-2 text-sm text-slate-500">Leave blank if you do not want to set a limit.</p><div class="mt-3 grid gap-3 sm:grid-cols-2">
        @foreach(['A','B','C','D'] as $letter)<label class="text-sm">Section {{ $letter }}<input type="number" min="1" max="65535" name="sections[{{ $letter }}][capacity]" value="{{ array_key_exists($letter, $savedSections) ? ($savedSections[$letter]['capacity'] ?? '') : ($sectionCapacities[$letter] ?? '') }}" data-section-capacity="{{ $letter }}" aria-invalid="{{ $errors->has('sections.'.$letter.'.capacity') ? 'true' : 'false' }}" placeholder="No limit set" class="mt-1 w-full rounded-lg border border-slate-300 bg-white p-3">@error('sections.'.$letter.'.capacity')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>@endforeach
        </div></details>
        @php($hasCustomSections = collect($savedSections)->except(['A','B','C','D'])->contains(fn ($row) => filled($row['name'] ?? '') || filled($row['capacity'] ?? '')))
        <details class="setup-custom-sections" @if($hasCustomSections) open @endif><summary>Other section names</summary>
        <div class="mt-4 space-y-3" data-custom-sections>
            @php($customSections = collect($savedSections)->except(['A','B','C','D'])->all() ?: ['custom0'=>['name'=>'']])
            @foreach($customSections as $key=>$row)
                <div class="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]" data-custom-section data-protected="{{ in_array($row['name'] ?? '', $lockedSectionNames, true) ? 'true' : 'false' }}">
                    <input type="hidden" name="sections[{{ $key }}][enabled]" value="1">
                    <label class="text-sm font-semibold">Another section<input name="sections[{{ $key }}][name]" value="{{ $row['name'] ?? '' }}" @readonly(in_array($row['name'] ?? '', $lockedSectionNames, true)) aria-invalid="{{ $errors->has('sections.'.$key.'.name') ? 'true' : 'false' }}" maxlength="50" placeholder="For example, E or Blue" class="mt-1 w-full rounded-lg border border-slate-300 p-3" data-section-name>@error('sections.'.$key.'.name')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>
                    <label class="text-sm">Student limit — optional<input type="number" min="1" max="65535" name="sections[{{ $key }}][capacity]" value="{{ $row['capacity'] ?? '' }}" aria-invalid="{{ $errors->has('sections.'.$key.'.capacity') ? 'true' : 'false' }}" placeholder="No limit set" class="mt-1 w-full rounded-lg border border-slate-300 p-3">@error('sections.'.$key.'.capacity')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror</label>
                    <button type="button" data-remove-section class="rounded-lg border border-slate-300 px-4 py-3 text-sm" aria-label="Remove custom section" @disabled(in_array($row['name'] ?? '', $lockedSectionNames, true)) hidden>Remove</button>
                </div>
            @endforeach
        </div>
        <template data-section-template><div class="grid items-end gap-3 sm:grid-cols-[1fr_1fr_auto]" data-custom-section>
            <input type="hidden" data-section-field="enabled" value="1">
            <label class="text-sm font-semibold">Another section<input data-section-field="name" data-section-name maxlength="50" placeholder="For example, E or Blue" class="mt-1 w-full rounded-lg border border-slate-300 p-3"></label>
            <label class="text-sm">Student limit — optional<input data-section-field="capacity" type="number" min="1" max="65535" placeholder="No limit set" class="mt-1 w-full rounded-lg border border-slate-300 p-3"></label>
            <button type="button" data-remove-section class="rounded-lg border border-slate-300 px-4 py-3 text-sm" aria-label="Remove custom section">Remove</button>
        </div></template>
        <button type="button" data-add-section class="mt-3 font-semibold text-[#8b2f24] underline" hidden>+ Add another section</button>
        </details>
        @if(!$schoolClass->exists)
        <label class="mt-4 flex items-center gap-3 text-sm"><input type="checkbox" name="skip_sections" value="1" data-skip-sections @checked(old('skip_sections')) class="h-4 w-4 accent-[#8b2f24]">I’ll add sections later</label>
        @endif
        @if($lockedSectionNames)<p class="mt-3 text-xs text-slate-500">{{ implode(', ', $lockedSectionNames) }}: already in use, so these sections stay selected. Student limits can still be updated.</p>@endif
        <p data-section-summary class="setup-selection-summary" aria-live="polite"></p>
        </fieldset>
        </div>
    </section>
    @endcan
</div>
    @can('create', \App\Models\ClassSubject::class)
    <section class="setup-card setup-subjects" data-setup-subjects aria-labelledby="subjects-heading">
        <div class="setup-card-header"><div class="setup-card-heading"><span class="setup-card-icon"><x-icon name="books" /></span><div><h2 id="subjects-heading">Subjects</h2><p>Build the subject list for every section.</p></div></div><span class="setup-badge setup-badge--muted">{{ $year ? $subjects->count().' available' : 'Optional' }}</span></div>
        <div class="setup-card-body">
        @if(!$setupEditable)<p class="mb-4 text-sm text-slate-500">Activate this class before changing subjects.</p>@endif
        <fieldset class="setup-fieldset" @disabled(!$setupEditable)>
        @php($savedSubjectIds = $errors->has('setup_revision') ? $selectedSubjectIds : old('subject_ids', $selectedSubjectIds))
        @if($year)
            <p class="mt-1 text-sm text-slate-500">Subjects for <strong>{{ $year->name }}</strong>. These apply to every section of this class.</p>
            <input type="hidden" name="academic_year_id" value="{{ $year->id }}">
            @if($subjects->isNotEmpty())
                <input type="hidden" name="subjects_present" value="1">
                <div class="setup-subject-toolbar"><label class="block text-sm font-semibold">Find a subject<input type="search" data-subject-search placeholder="Search by name or code" class="mt-2 w-full rounded-lg border border-slate-300 p-3" disabled></label>
                <p data-subject-count class="setup-subject-count" aria-live="polite"></p></div>
                <div class="setup-subject-list" data-subject-list>
                    @foreach($subjects as $subject)
                    <label data-subject-option data-search-text="{{ $subject->name }} {{ $subject->code }}" class="setup-subject-option">@if(in_array($subject->id, $lockedSubjectIds))<input type="hidden" name="subject_ids[]" value="{{ $subject->id }}">@endif<input type="checkbox" name="subject_ids[]" value="{{ $subject->id }}" data-locked="{{ in_array($subject->id, $lockedSubjectIds) ? 'true' : 'false' }}" @disabled(in_array($subject->id, $lockedSubjectIds)) @checked(in_array($subject->id, $savedSubjectIds) || in_array($subject->id, $lockedSubjectIds)) class="h-4 w-4 shrink-0 accent-[#8b2f24]"><span><span class="block text-sm font-semibold">{{ $subject->name }}</span>@if(in_array($subject->id, $lockedSubjectIds))<span class="text-xs text-slate-500">In use · kept selected</span>@endif</span></label>
                    @endforeach
                </div>
                <p data-subject-empty class="mt-3 text-sm text-slate-500" hidden>No subjects match your search. Your selected subjects are still kept.</p>
                @if($lockedSubjectIds)<p class="mt-3 text-xs text-slate-500">Subjects used by exams or assigned teachers stay selected, including when copying another class.</p>@endif
                @if($copyClasses->isNotEmpty())
                <details class="setup-copy-subjects"><summary class="cursor-pointer text-sm font-semibold">Copy subjects from another class</summary><label class="mt-3 block text-sm">Replace the selection with subjects from<select data-copy-subjects class="mt-2 w-full rounded-lg border border-slate-300 bg-white p-3" disabled><option value="">Choose a class</option>@foreach($copyClasses as $source)<option value="{{ $source->id }}" data-subjects="{{ $source->classSubjects->pluck('subject_id')->toJson() }}">{{ $source->name }}</option>@endforeach</select></label><p class="mt-2 text-xs text-slate-500">Review the selected subjects above before saving. Marks settings are configured separately.</p></details>
                @endif
            @else
                <p class="mt-4 rounded-lg bg-amber-50 p-4 text-sm text-amber-900">There are no active subjects yet. You can create this class now and add subjects afterwards.</p>
            @endif
        @else
            <p class="mt-3 rounded-lg bg-amber-50 p-4 text-sm text-amber-900">Set an active current academic year before assigning subjects. You can still create the class and its sections now.</p>
            @can('create', \App\Models\AcademicYear::class)<a href="{{ route('academic-years.create') }}" target="_blank" rel="noopener" class="mt-3 inline-block text-sm font-semibold text-[#8b2f24] underline">Set up an academic year in a new tab</a>@endcan
        @endif
        @if($retainedSubjects->isNotEmpty())<p class="mt-4 text-sm text-slate-500">Existing inactive subjects are retained: {{ $retainedSubjects->pluck('name')->join(', ') }}.</p>@endif
        </fieldset>
        </div>
    </section>
    @endcan
@if($teacherPanelData !== null)
    @include('classes.partials.teachers')
@endif
<div class="setup-form-footer"><p>{{ $schoolClass->exists ? 'Review your changes, then save.' : 'Your class, sections and subjects are saved together.' }}</p><div class="setup-form-actions"><a href="{{ $schoolClass->exists ? route('classes.show',$schoolClass) : route('classes.index') }}" class="setup-cancel">Cancel</a><button class="setup-save"><x-icon :name="$schoolClass->exists ? 'assignment' : 'plus'" />{{ $schoolClass->exists ? 'Save class' : 'Create class' }}</button></div></div>
</form>
</x-app-layout>
