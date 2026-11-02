@php
    $prefix = 'guardians.'.$key;
    $htmlPrefix = 'guardians['.$key.']';
    $option = $row['option'] ?? null;
    $saved = !empty($row['saved_id']);
    $removed = !empty($row['removed']);
    $inactive = ($option['status'] ?? 'ACTIVE') !== 'ACTIVE';
    $details = is_array($row['details'] ?? null) ? $row['details'] : [];
    $cardName = $option['name'] ?? trim((is_string($details['first_name'] ?? null) ? $details['first_name'] : '').' '.(is_string($details['last_name'] ?? null) ? $details['last_name'] : ''));
    $open = ! $removed && (!$saved || $errors->has($prefix.'.*'));
@endphp
<details class="guardian-card {{ $removed ? 'is-removed' : '' }}" data-guardian-card data-key="{{ $key }}" data-saved-id="{{ $row['saved_id'] ?? '' }}" data-inactive="{{ $inactive ? 'true' : 'false' }}" @if($open) open @endif>
    <summary><span class="guardian-card-avatar"><x-icon name="users" /></span><span class="guardian-card-summary"><strong data-guardian-name>{{ $cardName ?: 'New guardian' }}</strong><span data-guardian-phone>{{ $option['phone'] ?? (is_string($details['phone'] ?? null) ? $details['phone'] : '') }}</span><span data-guardian-summary>{{ ucfirst(strtolower($row['relationship'] ?? 'GUARDIAN')) }}{{ !empty($row['primary']) ? ' · Primary contact' : '' }}{{ $inactive ? ' · Inactive' : '' }}</span></span><span class="guardian-card-edit" data-guardian-edit-label>Edit</span></summary>
    <fieldset data-guardian-fields class="guardian-card-body">
        @if($saved)
            <input type="hidden" name="{{ $htmlPrefix }}[kind]" value="existing" data-guardian-kind>
            <input type="hidden" name="{{ $htmlPrefix }}[guardian_id]" value="{{ $row['guardian_id'] }}" data-guardian-id>
        @else
            <div class="guardian-card-type"><label><input type="radio" name="{{ $htmlPrefix }}[kind]" value="new" data-guardian-kind @checked(($row['kind'] ?? 'new') === 'new')>New contact</label><label><input type="radio" name="{{ $htmlPrefix }}[kind]" value="existing" data-guardian-kind @checked(($row['kind'] ?? 'new') === 'existing')>Existing contact</label></div>
            <fieldset data-guardian-picker>
                <div class="student-field"><label for="guardian-search-{{ $key }}">Find a guardian</label><input id="guardian-search-{{ $key }}" type="search" maxlength="100" placeholder="Name or phone number" data-guardian-search autocomplete="off"><p class="student-field-hint" role="status" data-guardian-search-status>Search existing contacts to reuse a sibling’s guardian.</p><button type="button" class="guardian-search-retry" data-guardian-search-retry hidden>Try again</button></div>
                <div class="student-field mt-3"><label for="guardians-{{ $key }}-guardian_id">Guardian *</label><select id="guardians-{{ $key }}-guardian_id" name="{{ $htmlPrefix }}[guardian_id]" data-guardian-id><option value="">Choose a guardian</option>@foreach($guardianOptions as $choice)<option value="{{ $choice['id'] }}" @selected((string)($row['guardian_id'] ?? '') === (string)$choice['id'])>{{ $choice['name'] }} · {{ $choice['phone'] }}</option>@endforeach</select>@error($prefix.'.guardian_id')<p class="student-field-error">{{ $message }}</p>@enderror</div>
            </fieldset>
        @endif
        <input type="hidden" name="{{ $htmlPrefix }}[revision]" value="{{ $row['revision'] ?? '' }}" data-guardian-revision>
        @error($prefix.'.revision')<p class="student-field-error">{{ $message }}</p>@enderror
        <fieldset data-guardian-edit-contact>
            <input type="hidden" name="{{ $htmlPrefix }}[edit_contact]" value="0">
            <label class="student-check-option"><input type="checkbox" name="{{ $htmlPrefix }}[edit_contact]" value="1" data-guardian-edit-contact-toggle @checked(!empty($row['edit_contact']))><span>Edit contact details</span></label>
            <p class="guardian-shared-note" data-guardian-shared-note></p>
        </fieldset>
        @if($inactive)<p class="guardian-shared-note">This guardian is inactive. You can retain this contact unchanged or remove the link.</p>@endif
        <fieldset data-guardian-contact-fields>
            <div class="student-field-grid">
                <x-student-form-field :name="$prefix.'.details.first_name'" label="First name" :value="$details['first_name'] ?? null" :use-old="false" maxlength="100" data-contact-field="first_name" data-contact-required />
                <x-student-form-field :name="$prefix.'.details.last_name'" label="Last name" :value="$details['last_name'] ?? null" :use-old="false" maxlength="100" data-contact-field="last_name" />
                <x-student-form-field :name="$prefix.'.details.phone'" label="Phone number" type="tel" :value="$details['phone'] ?? null" :use-old="false" maxlength="30" data-contact-field="phone" data-contact-required />
            </div>
            <details class="student-form-details" @if($errors->has($prefix.'.details.*')) open @endif><summary>More contact details <span>Email, alternate phone and address</span></summary><div class="student-details-content"><div class="student-field-grid">
                <x-student-form-field :name="$prefix.'.details.email'" label="Email address" type="email" :value="$details['email'] ?? null" :use-old="false" data-contact-field="email" />
                <x-student-form-field :name="$prefix.'.details.alternate_phone'" label="Alternate phone" type="tel" :value="$details['alternate_phone'] ?? null" :use-old="false" maxlength="30" data-contact-field="alternate_phone" />
                <x-student-form-field :name="$prefix.'.details.occupation'" label="Occupation" :value="$details['occupation'] ?? null" :use-old="false" data-contact-field="occupation" />
            </div><input type="hidden" name="{{ $htmlPrefix }}[details][same_address]" value="0"><label class="student-check-option mt-4"><input type="checkbox" name="{{ $htmlPrefix }}[details][same_address]" value="1" data-guardian-same-address @checked($details['same_address'] ?? (($row['kind'] ?? 'new') === 'new'))><span>Use the student’s address</span></label><div class="student-field" data-guardian-address><label for="guardians-{{ $key }}-details-address">Guardian address</label><textarea id="guardians-{{ $key }}-details-address" name="{{ $htmlPrefix }}[details][address]" maxlength="2000" rows="3" data-contact-field="address">{{ is_string($details['address'] ?? null) ? $details['address'] : '' }}</textarea>@error($prefix.'.details.address')<p class="student-field-error">{{ $message }}</p>@enderror</div></div></details>
        </fieldset>
        <div class="student-field mt-4"><label for="guardian-relationship-{{ $key }}">Relationship to student *</label><select id="guardian-relationship-{{ $key }}" name="{{ $htmlPrefix }}[relationship]" data-guardian-relationship>@foreach(\App\Enums\GuardianRelationship::cases() as $relationship)<option value="{{ $relationship->value }}" @selected(($row['relationship'] ?? 'GUARDIAN') === $relationship->value)>{{ ucfirst(strtolower($relationship->value)) }}</option>@endforeach</select>@error($prefix.'.relationship')<p class="student-field-error">{{ $message }}</p>@enderror</div>
        <div class="guardian-contact-preferences"><label class="student-check-option"><input type="radio" name="primary_guardian" value="{{ $key }}" data-guardian-primary @checked(!empty($row['primary'])) @disabled($inactive)><span>Primary contact</span></label><input type="hidden" name="{{ $htmlPrefix }}[is_emergency_contact]" value="0"><label class="student-check-option"><input type="checkbox" name="{{ $htmlPrefix }}[is_emergency_contact]" value="1" data-guardian-emergency @checked($row['is_emergency_contact'] ?? true)><span>Emergency contact</span></label></div>
        <div class="guardian-card-actions"><button type="button" data-guardian-remove><x-icon name="close" />Remove guardian</button><button type="button" data-guardian-done>Done</button></div>
    </fieldset>
    <div class="guardian-removed-message" data-guardian-removed-message hidden><span>{{ $saved ? 'This link will be removed when you save. Contact history is retained.' : 'This guardian will not be added.' }}</span><button type="button" data-guardian-undo>Undo</button></div>
</details>
