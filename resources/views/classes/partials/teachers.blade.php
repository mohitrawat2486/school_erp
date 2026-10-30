<section class="setup-card setup-teachers" data-class-teachers data-teacher-options-url="{{ route('classes.teacher-options') }}" aria-labelledby="teachers-heading">
    <div class="setup-card-header"><div class="setup-card-heading"><span class="setup-card-icon"><x-icon name="teacher" /></span><div><h2 id="teachers-heading">Teachers</h2><p>Choose teachers here. Everything saves with the class.</p></div></div><span class="setup-badge setup-badge--muted">Optional</span></div>
    <div class="setup-card-body">
    @if($year && $setupEditable)
        <input type="hidden" name="academic_year_id" value="{{ $year->id }}">
        <input type="hidden" name="teacher_changes" value="[]" data-teacher-changes disabled>
        <script type="application/json" data-teacher-state>@json($teacherPanelData)</script>
        <div class="teacher-panel-intro"><p>Assignments for <strong>{{ $year->name }}</strong>. Choose a class teacher for each section and subject teachers for the subjects you selected.</p><span class="setup-badge setup-badge--muted" data-teacher-draft-count>Not assigned yet</span></div>
        <div data-teacher-content hidden>
            <div class="teacher-role-heading"><h3>Class teachers</h3><p>The teacher responsible for each section.</p></div>
            <div data-class-teacher-rows class="teacher-assignment-list"></div>
            <div class="teacher-role-heading"><h3>Subject teachers</h3><p>Choose one teacher for all selected sections, or use different teachers by section.</p></div>
            <div data-subject-teacher-rows class="teacher-assignment-list"></div>
            <p class="teacher-save-note">Teacher selections are optional. Choosing a teacher replaces existing teachers for the selected sections. Previous assignments stay in history. Changes take effect when you save this class.</p>
        </div>
        <noscript><p class="mt-3 text-sm text-slate-500">Save the class first, then open <a href="{{ route('assignments.index') }}" class="font-semibold underline">Teacher Assignments</a> to choose teachers.</p></noscript>
        <dialog data-teacher-dialog class="teacher-picker" aria-labelledby="teacher-picker-heading" aria-describedby="teacher-picker-description">
            <div class="teacher-picker-header"><div><p class="teacher-picker-eyebrow">Teacher directory</p><h2 id="teacher-picker-heading" data-teacher-picker-heading>Choose teacher</h2><p id="teacher-picker-description" data-teacher-picker-description>Active teachers in your school.</p></div><button type="button" data-teacher-close class="icon-button" aria-label="Close teacher picker"><x-icon name="close" /></button></div>
            <div class="teacher-picker-search"><label for="class-teacher-search">Find a teacher</label><div><x-icon name="search" /><input type="search" id="class-teacher-search" data-teacher-search maxlength="100" placeholder="Search name, specialisation or employee number" autocomplete="off"></div></div>
            <div data-teacher-results class="teacher-picker-results" aria-busy="false"></div>
            <p data-teacher-picker-status class="teacher-picker-status" role="status" aria-live="polite"></p>
            <button type="button" data-teacher-retry class="teacher-picker-retry" hidden>Try again</button>
            <div class="teacher-picker-footer"><span data-teacher-page-label></span><div><button type="button" data-teacher-previous aria-label="Previous teacher page"><x-icon name="chevron-left" /></button><button type="button" data-teacher-next aria-label="Next teacher page"><x-icon name="chevron-right" /></button></div></div>
        </dialog>
    @else
        <p class="text-sm text-slate-500">{{ $year ? 'Activate this class before changing teacher assignments.' : 'Set an active current academic year to assign teachers. You can save the class first.' }}</p>
    @endif
    </div>
</section>
