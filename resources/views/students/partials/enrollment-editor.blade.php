<section class="student-form-card student-enrollment-editor" aria-labelledby="enrollment-heading" data-enrollment-editor data-options-url="{{ route('students.enrollment-options', $student->exists ? ['student_id' => $student->id] : []) }}">
    <div class="student-card-heading"><span class="student-card-icon"><x-icon name="classes" /></span><div><h2 id="enrollment-heading">Enrollment <span class="student-optional" data-enrollment-badge>{{ $enrollmentState['enrollment']['status'] ?? 'Optional' }}</span></h2><p>Assign a class and section without leaving this form.</p></div></div>
    <noscript><p class="guardian-shared-note">Enable JavaScript to manage enrollment here. Student details can still be saved without changing enrollment.</p></noscript>
    <script type="application/json" data-enrollment-initial>{!! \Illuminate\Support\Js::encode(['state' => $enrollmentState, 'draft' => $enrollmentDraft, 'departure' => $departureDraft, 'operation_key' => $departureOperationKey, 'today' => today()->toDateString(), 'student_name' => $student->exists ? $student->fullName() : null, 'admission_number' => $student->admission_number, 'enrollment_errors' => $errors->has('enrollment') || $errors->has('enrollment.*'), 'departure_errors' => $errors->has('departure') || $errors->has('departure.*')]) !!}</script>
    <div class="enrollment-compact-summary" data-enrollment-compact></div>
    <div class="enrollment-workspace-actions"><button type="button" class="enrollment-manage-button" data-enrollment-open><x-icon name="classes" /><span>Manage enrollment</span></button><button type="button" data-departure-open="WITHDRAW">Withdraw</button><button type="button" data-departure-open="TRANSFER">Transfer</button></div>
    <p class="student-field-hint" data-departure-availability></p>
    <div class="enrollment-pending" data-student-action-pending hidden><div><strong data-student-action-title></strong><p data-student-action-description></p></div><div><button type="button" data-student-action-edit>Edit</button><button type="button" data-student-action-clear>Cancel pending action</button></div></div>
    <dialog class="student-workflow-dialog" data-enrollment-dialog aria-labelledby="enrollment-modal-heading">
        <div class="student-modal-header"><div><h2 id="enrollment-modal-heading">Manage enrollment</h2><p>Apply changes here, then save the student to record them.</p></div><button type="button" class="icon-button" data-enrollment-dialog-cancel aria-label="Close enrollment dialog"><x-icon name="close" /></button></div>
        <div class="student-modal-content">
    <fieldset data-enrollment-editor-fields disabled>
        <div class="student-field"><label for="enrollment-year">Academic year</label><select id="enrollment-year" name="enrollment[academic_year_id]" data-enrollment-year><option value="">Choose an academic year</option>@foreach($enrollmentYears as $year)<option value="{{ $year->id }}" @selected(($enrollmentState['year']['id'] ?? null) === $year->id)>{{ $year->name }}{{ $year->is_current ? ' · Current' : '' }}{{ $year->status->value !== 'ACTIVE' ? ' · '.ucfirst(strtolower($year->status->value)) : '' }}</option>@endforeach</select>@error('enrollment.academic_year_id')<p class="student-field-error">{{ $message }}</p>@enderror</div>
        <input type="hidden" name="enrollment[action]" value="keep" data-enrollment-action>
        <div class="enrollment-current" data-enrollment-summary hidden></div>
        <p class="enrollment-readonly-note" data-enrollment-reason></p>
        <label class="student-check-option enrollment-enable" data-enrollment-enable-label><input type="checkbox" data-enrollment-enable><span data-enrollment-enable-text>Assign a class now</span></label>
        <p class="student-field-hint" data-enrollment-kept>Leave this off to save student details without changing enrollment.</p>
        <p class="student-field-hint" role="status" data-enrollment-loading hidden>Loading enrollment details…</p><p class="student-field-error" role="status" data-enrollment-fetch-error></p><button type="button" class="guardian-search-retry" data-enrollment-retry hidden>Try again</button>
        @error('enrollment')<p class="student-field-error">{{ $message }}</p>@enderror
        @error('enrollment.revision')<p class="student-field-error">{{ $message }}</p>@enderror
        <fieldset data-enrollment-body hidden>
            <input type="hidden" name="enrollment[revision]" data-enrollment-revision>
            <div class="enrollment-correction" data-enrollment-correction hidden><button type="button" data-enrollment-change>Change class / section</button><p class="student-field-hint">Use this to correct an unused placement. Existing academic records are protected.</p></div>
            <p class="student-field-hint" data-enrollment-placement-reason></p>
            <input type="hidden" name="enrollment[correct_placement]" value="0" data-enrollment-correct>
            <input type="hidden" name="enrollment[class_id]" data-enrollment-fixed-class>
            <input type="hidden" name="enrollment[section_id]" data-enrollment-fixed-section>
            <fieldset data-enrollment-placement class="student-field-grid">
                <div class="student-field"><label for="enrollment-class">Class *</label><select id="enrollment-class" name="enrollment[class_id]" data-enrollment-class><option value="">Choose a class</option></select>@error('enrollment.class_id')<p class="student-field-error">{{ $message }}</p>@enderror</div>
                <div class="student-field"><label for="enrollment-section">Section *</label><select id="enrollment-section" name="enrollment[section_id]" data-enrollment-section><option value="">Choose a class first</option></select>@error('enrollment.section_id')<p class="student-field-error">{{ $message }}</p>@enderror</div>
            </fieldset>
            <p class="enrollment-seats" data-enrollment-seats role="status"></p>
            <div class="student-field-grid mt-4">
                <div class="student-field"><label for="enrollment-roll-mode">Roll number</label><select id="enrollment-roll-mode" name="enrollment[roll_mode]" data-enrollment-roll-mode><option value="auto">Assign automatically</option><option value="manual">Enter manually</option></select><p class="student-field-hint" data-enrollment-roll-hint></p><input id="enrollment-roll" name="enrollment[roll_number]" type="number" min="1" max="4294967295" step="1" inputmode="numeric" aria-label="Manual roll number" data-enrollment-roll>@error('enrollment.roll_number')<p class="student-field-error">{{ $message }}</p>@enderror</div>
                <div class="student-field"><label for="enrollment-joined">Joining date *</label><input id="enrollment-joined" name="enrollment[joined_at]" type="date" aria-describedby="enrollment-joined-help" data-enrollment-joined><p id="enrollment-joined-help" class="student-field-hint">Date the student joined this academic year.</p>@error('enrollment.joined_at')<p class="student-field-error">{{ $message }}</p>@enderror</div>
            </div>
            <label class="student-check-option mt-4" data-enrollment-reactivate-label hidden><input type="checkbox" name="enrollment[reactivate]" value="1" data-enrollment-reactivate><span>Reactivate this enrollment when saving</span></label>
        </fieldset>
    </fieldset>
        </div>
        <div class="student-modal-footer"><button type="button" class="student-cancel" data-enrollment-dialog-cancel>Cancel</button><button type="button" class="button-primary" data-enrollment-apply>Apply enrollment changes</button></div>
    </dialog>
    <input type="hidden" name="departure[action]" value="keep" data-departure-action disabled>
    <dialog class="student-workflow-dialog student-departure-dialog" data-departure-dialog aria-labelledby="departure-modal-heading">
        <div class="student-modal-header"><div><h2 id="departure-modal-heading" data-departure-heading>Withdraw student</h2><p>The selected enrollment will be updated when you save the student.</p></div><button type="button" class="icon-button" data-departure-cancel aria-label="Close departure dialog"><x-icon name="close" /></button></div>
        <div class="student-modal-content">
            <p class="student-departure-identity" data-departure-identity></p>
            <p class="student-field-hint" data-departure-summary></p>
            <fieldset data-departure-fields disabled>
                <input type="hidden" name="departure[academic_year_id]" data-departure-year>
                <input type="hidden" name="departure[revision]" data-departure-revision>
                <input type="hidden" name="departure[operation_key]" data-departure-operation-key value="{{ $departureOperationKey }}">
                <div class="student-field mt-4"><label for="departure-date" data-departure-date-label>Withdrawal date *</label><input id="departure-date" type="date" name="departure[departure_date]" data-departure-date required>@error('departure.departure_date')<p class="student-field-error">{{ $message }}</p>@enderror</div>
                <div class="student-field mt-4"><label for="departure-reason">Reason *</label><textarea id="departure-reason" name="departure[reason]" rows="3" maxlength="500" data-departure-reason required></textarea>@error('departure.reason')<p class="student-field-error">{{ $message }}</p>@enderror</div>
                <div class="student-field mt-4" data-departure-destination-area><label for="departure-destination">Destination school <span class="student-optional">Optional</span></label><input id="departure-destination" name="departure[destination_school]" maxlength="255" data-departure-destination>@error('departure.destination_school')<p class="student-field-error">{{ $message }}</p>@enderror</div>
                <label class="student-check-option mt-4"><input type="checkbox" name="departure[confirmed]" value="1" data-departure-confirm required><span data-departure-confirm-label>I confirm this withdrawal will be recorded when I save the student. Academic records and contact history will be retained.</span></label>
            </fieldset>
            @foreach(['departure', 'departure.revision', 'departure.operation_key', 'departure.confirmed'] as $errorKey)@error($errorKey)<p class="student-field-error">{{ $message }}</p>@enderror @endforeach
            <p class="student-field-error" role="status" data-departure-dialog-error></p>
        </div>
        <div class="student-modal-footer"><button type="button" class="student-cancel" data-departure-cancel>Cancel</button><button type="button" class="button-primary" data-departure-apply>Add withdrawal to changes</button></div>
    </dialog>
</section>
