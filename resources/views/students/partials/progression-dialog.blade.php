<noscript><p class="mt-4 text-sm text-slate-500">Enable JavaScript to move students to the next academic year.</p></noscript>
<dialog class="student-workflow-dialog progression-dialog" data-progression-dialog aria-labelledby="progression-heading" data-roster-url="{{ route('students.progression.roster') }}" data-review-url="{{ route('students.progression.review') }}" data-store-url="{{ route('students.progression.store') }}">
    <script type="application/json" data-progression-initial>{!! \Illuminate\Support\Js::encode($progressionCatalog + ['defaults' => ['year' => $year?->id, 'class' => $filters['class_id'] ?? null, 'section' => $filters['section_id'] ?? null]]) !!}</script>
    <div class="student-modal-header"><div><h2 id="progression-heading">Move to next academic year</h2><p>Promote or repeat students together. Previous-year records stay intact.</p></div><button type="button" class="icon-button" data-progression-close aria-label="Close year-end workflow"><x-icon name="close" /></button></div>
    <ol class="progression-steps" aria-label="Year-end progress"><li data-progression-step-label="1" aria-current="step"><span>1</span>Students</li><li data-progression-step-label="2"><span>2</span>Destinations</li><li data-progression-step-label="3"><span>3</span>Review</li></ol>
    <div class="student-modal-content">
        <div class="progression-error" data-progression-error role="alert" hidden></div>
        <button type="button" class="progression-text-button" data-progression-retry hidden>Retry loading students</button>
        <p class="student-field-hint" data-progression-loading role="status" hidden>Loading…</p>
        <form data-progression-form novalidate>@csrf
            <section data-progression-step="1" aria-label="Choose students">
                <div class="progression-source-grid">
                    <div class="student-field"><label for="progression-source-year">From academic year *</label><select id="progression-source-year" data-progression-source-year required></select></div>
                    <div class="student-field"><label for="progression-source-class">Class *</label><select id="progression-source-class" data-progression-source-class required></select></div>
                    <div class="student-field"><label for="progression-source-section">Section *</label><select id="progression-source-section" data-progression-source-section required></select></div>
                </div>
                <p class="student-field-hint mt-3">Choose a section, then select the students ready to move. Already processed students are shown for reference.</p>
                <div class="progression-roster-toolbar"><div class="student-field"><label for="progression-search">Search this section</label><input id="progression-search" type="search" maxlength="100" placeholder="Name or admission number" data-progression-search></div><button type="button" class="progression-text-button" data-progression-clear-selection>Clear selection</button></div>
                <div class="progression-selection-summary" data-progression-selection role="status" aria-live="polite">No students selected.</div>
                <div class="progression-table-region" role="region" aria-label="Students in selected section" tabindex="0"><table class="progression-table"><thead><tr><th><input type="checkbox" data-progression-select-page aria-label="Select eligible students on this page"></th><th>Student</th><th>Roll</th><th>Readiness</th></tr></thead><tbody data-progression-roster><tr><td colspan="4">Choose an academic year, class and section to load students.</td></tr></tbody></table></div>
                <div class="progression-pagination"><p data-progression-page-summary></p><nav class="list-pagination" aria-label="Student selection pagination"><button type="button" class="pagination-link" data-progression-previous><x-icon name="chevron-left" /><span>Previous</span></button><span data-progression-page></span><button type="button" class="pagination-link" data-progression-next><span>Next</span><x-icon name="chevron-right" /></button></nav></div>
                <p class="student-field-hint">Selections stay while searching and changing pages. Process up to 100 students per batch; leave undecided students for later.</p>
                <p class="student-field-hint" data-progression-context-reset role="status"></p>
            </section>
            <section data-progression-step="2" aria-label="Choose destinations" hidden>
                <div class="progression-source-grid">
                    <div class="student-field"><label for="progression-target-year">Next academic year *</label><select id="progression-target-year" data-progression-target-year required></select></div>
                    <div class="student-field"><label for="progression-joined">Joining date *</label><input id="progression-joined" type="date" data-progression-joined required></div>
                </div>
                <div class="progression-destination" data-progression-default-destination><h3>Destination for promoted students</h3><div class="student-field-grid"><div class="student-field"><label for="progression-target-class">Next class *</label><select id="progression-target-class" data-progression-target-class></select></div><div class="student-field"><label for="progression-target-section">Section *</label><select id="progression-target-section" data-progression-target-section></select></div></div></div>
                <p class="student-field-hint mt-4">Choose Repeat for students staying in the same class. Select their section below. Leave roll numbers blank for automatic allocation.</p>
                <div class="progression-table-region progression-cards mt-4" role="region" aria-label="Student decisions and roll numbers" tabindex="0"><table class="progression-table"><thead><tr><th>Student</th><th>Decision</th><th>Section for repeating</th><th>New roll</th></tr></thead><tbody data-progression-decisions></tbody></table></div>
            </section>
            <section data-progression-step="3" aria-label="Review moves" hidden>
                <div class="progression-review-summary"><h3 data-progression-review-counts></h3><p data-progression-review-context></p><p>Existing enrollments, marks and results will be retained.</p></div>
                <div class="progression-table-region progression-cards mt-4" role="region" aria-label="Reviewed placements" tabindex="0"><table class="progression-table"><thead><tr><th>Student</th><th>Decision</th><th>New placement</th><th>New roll</th></tr></thead><tbody data-progression-review-rows></tbody></table></div>
                <label class="student-check-option mt-4"><input type="checkbox" data-progression-confirm required><span>I have reviewed these students, decisions, destinations and roll numbers. Record these outcomes once for the selected source enrollments.</span></label>
            </section>
        </form>
        <section class="progression-success" data-progression-success hidden aria-label="Moves completed"><span class="student-card-icon"><x-icon name="check" /></span><h3>Students moved successfully</h3><p data-progression-success-counts></p><p>You can view the outcome in each student’s enrollment history.</p><a class="progression-text-button" data-progression-history>View this batch’s recorded outcomes<x-icon name="chevron-right" /></a></section>
    </div>
    <div class="student-modal-footer"><button type="button" class="student-cancel" data-progression-cancel>Cancel</button><button type="button" class="student-cancel" data-progression-back hidden>Back</button><button type="button" class="button-primary" data-progression-continue>Continue</button><button type="button" class="student-cancel" data-progression-remaining hidden>Process remaining students</button><button type="button" class="button-primary" data-progression-done hidden>Done</button></div>
</dialog>
