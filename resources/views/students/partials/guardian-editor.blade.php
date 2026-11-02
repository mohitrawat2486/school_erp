<section class="student-form-card" aria-labelledby="guardian-heading" data-guardian-editor data-options-url="{{ route('students.guardian-editor-options', $student->exists ? ['student_id' => $student->id] : []) }}">
    <div class="student-card-heading"><span class="student-card-icon"><x-icon name="users" /></span><div><h2 id="guardian-heading">Guardians <span class="student-optional" data-guardian-count>0 of 3</span></h2><p>Add up to three parents or guardians. Keep one as the primary contact.</p></div></div>
    <noscript><p class="guardian-shared-note">Enable JavaScript to manage guardian cards here. You can save student details without changing guardian contacts.</p></noscript>
    <fieldset data-guardian-editor-fields disabled>
        <input type="hidden" name="guardians_present" value="1">
        @if($guardianRevision)<input type="hidden" name="guardian_setup_revision" value="{{ $guardianRevision }}">@endif
        <script type="application/json" data-guardian-options>{!! \Illuminate\Support\Js::encode($guardianOptions) !!}</script>
        @error('guardians')<p class="student-field-error">{{ $message }}</p>@enderror
        @error('guardian_setup_revision')<p class="student-field-error">{{ $message }}</p>@enderror
        @error('primary_guardian')<p class="student-field-error">{{ $message }}</p>@enderror
        @if($guardianLegacyCount > 3)<p class="guardian-shared-note">This student already has {{ $guardianLegacyCount }} contacts. Existing records are retained; reduce the list to fewer than three before adding another.</p>@endif
        <div data-guardian-cards class="guardian-card-list">@foreach($guardianRows as $key => $row)@include('students.partials.guardian-card')@endforeach</div>
        <p class="guardian-later-note" data-guardian-empty>No guardian added yet. You can add contacts now or return to this form later.</p>
        <button type="button" class="guardian-add-button" data-guardian-add><x-icon name="plus" /><span>Add guardian</span></button>
        <p class="student-field-hint" data-guardian-limit hidden>Maximum of 3 guardians reached.</p>
        <p class="student-field-hint" data-guardian-notice role="status"></p>
        <div class="guardian-removal-review" data-guardian-removals hidden><strong>Review guardian removals</strong><p data-guardian-removal-names></p><input type="hidden" name="confirm_guardian_removals" value="0"><label class="student-check-option"><input type="checkbox" name="confirm_guardian_removals" value="1" data-guardian-confirm @checked(old('confirm_guardian_removals'))><span>Remove these links when I save. Keep the guardian records and history.</span></label>@error('confirm_guardian_removals')<p class="student-field-error">{{ $message }}</p>@enderror</div>
    </fieldset>
    <template data-guardian-template>@include('students.partials.guardian-card', ['key' => '__KEY__', 'row' => ['kind' => 'new', 'details' => [], 'primary' => false, 'relationship' => 'GUARDIAN', 'is_emergency_contact' => true]])</template>
</section>
