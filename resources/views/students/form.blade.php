<x-app-layout :title="$student->exists ? 'Edit Student' : 'Add Student'" :full-width="true">
<a href="{{ $student->exists ? route('students.show', $student) : route('students.index') }}" class="workspace-back">← {{ $student->exists ? $student->fullName() : 'Students' }}</a>
<div class="student-form-header"><div><p class="student-form-eyebrow">Students</p><h1>{{ $student->exists ? 'Edit Student' : 'Add Student' }}</h1><p>{{ $student->exists ? 'Keep student, guardian and enrollment details up to date.' : 'Add student details, guardians and class enrollment in one place.' }}</p></div><span class="student-required-note">* Required fields</span></div>
<x-admin-feedback />
<form action="{{ $student->exists ? route('students.update', $student) : route('students.store') }}" method="POST" enctype="multipart/form-data" class="student-admission-form" data-student-admission>
@csrf
@if($student->exists)
@method('PUT')
@endif
<div class="student-admission-grid">
    <div class="student-admission-column">
        <section class="student-form-card" aria-labelledby="student-details-heading">
            <div class="student-card-heading"><span class="student-card-icon"><x-icon name="users" /></span><div><h2 id="student-details-heading">Student details</h2><p>Start with the student’s name and admission information.</p></div></div>
            <div class="student-field-grid">
                <x-student-form-field name="first_name" label="First name" :value="$student->first_name" maxlength="100" autocomplete="section-student given-name" required />
                <x-student-form-field name="last_name" label="Last name" :value="$student->last_name" maxlength="100" autocomplete="section-student family-name" />
                <x-student-form-field name="admission_number" label="Admission number" :value="$student->admission_number" maxlength="50" required hint="Use the unique number from your admission register." />
                <x-student-form-field name="admission_date" label="Admission date" type="date" :value="$student->admission_date?->format('Y-m-d') ?? now()->toDateString()" required />
                <x-student-form-field name="aadhaar_number" label="Aadhaar number" required :value="$student->aadhaar_number" maxlength="12" minlength="12" pattern="[0-9]{12}" inputmode="numeric" autocomplete="off" hint="Identification: enter the 12-digit number without spaces." />
            </div>
            <details class="student-form-details" @if(old('middle_name') || old('date_of_birth') || old('gender') || old('blood_group') || $student->exists || $errors->hasAny(['middle_name', 'date_of_birth', 'gender', 'blood_group'])) open @endif>
                <summary>More personal details <span>Middle name, date of birth and more</span></summary>
                <div class="student-field-grid student-details-content">
                    <x-student-form-field name="middle_name" label="Middle name" :value="$student->middle_name" maxlength="100" autocomplete="section-student additional-name" />
                    <x-student-form-field name="date_of_birth" label="Date of birth" type="date" :value="$student->date_of_birth?->format('Y-m-d')" autocomplete="section-student bday" />
                    <div class="student-field"><label for="gender">Gender</label><select id="gender" name="gender"><option value="">Not specified</option>@foreach(\App\Models\Student::GENDERS as $value => $label)<option value="{{ $value }}" @selected(old('gender', $student->gender) === $value)>{{ $label }}</option>@endforeach</select>@error('gender')<p class="student-field-error">{{ $message }}</p>@enderror</div>
                    <div class="student-field"><label for="blood_group">Blood group</label><select id="blood_group" name="blood_group"><option value="">Not specified</option>@foreach(\App\Models\Student::BLOOD_GROUPS as $group)<option value="{{ $group }}" @selected(old('blood_group', $student->blood_group) === $group)>{{ $group }}</option>@endforeach</select>@error('blood_group')<p class="student-field-error">{{ $message }}</p>@enderror</div>
                </div>
            </details>
        </section>
        @can('create', \App\Models\StudentEnrollment::class)
            @include('students.partials.enrollment-editor')
        @endcan
        <section class="student-form-card" aria-labelledby="student-address-heading">
            <div class="student-card-heading"><span class="student-card-icon"><x-icon name="classes" /></span><div><h2 id="student-address-heading">Address &amp; photo <span class="student-optional">Optional</span></h2><p>Add these now or complete them later.</p></div></div>
            <div class="student-field"><label for="address">Student address</label><textarea id="address" name="address" rows="3" maxlength="2000" autocomplete="section-student street-address" placeholder="House, street, city and postal code">{{ is_string(old('address', $student->address)) ? old('address', $student->address) : '' }}</textarea>@error('address')<p class="student-field-error">{{ $message }}</p>@enderror</div>
            <div class="student-photo-upload" data-admission-photo>
                <div class="student-photo-placeholder" data-student-photo-current>@if($student->photo_path)<img src="{{ route('students.photo', $student) }}" alt="Current student photo" width="72" height="72">@else<x-icon name="users" />@endif</div>
                <img data-student-photo-preview hidden alt="Selected student photo" width="72" height="72">
                <div class="student-field"><label for="photo">Student photo</label><input id="photo" name="photo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="photo-help"><p id="photo-help" class="student-field-hint">JPEG, PNG or WebP, up to 2 MB and 2000 × 2000 pixels. {{ $student->photo_path ? 'Leave empty to keep the current photo.' : 'Choose a clear portrait.' }}</p>@error('photo')<p class="student-field-error">{{ $message }}</p>@enderror</div>
            </div>
        </section>
    </div>
    <div class="student-admission-column">
        @can('create', \App\Models\Guardian::class)
            @include('students.partials.guardian-editor')
        @else
            <section class="student-form-card"><div class="student-card-heading"><span class="student-card-icon"><x-icon name="users" /></span><div><h2>Guardians</h2><p>A staff member with guardian management access can add contacts after the student is saved.</p></div></div></section>
        @endcan
        <p class="student-form-footnote"><x-icon name="classes" /><span>Student details, guardian contacts and enrollment are saved together.</span></p>
    </div>
</div>
<div class="student-form-actions"><p>{{ $student->exists ? 'Student, guardian and enrollment changes are saved together.' : 'Student, guardian and enrollment details will be saved together.' }}</p><div><a href="{{ $student->exists ? route('students.show', $student) : route('students.index') }}" class="student-cancel">Cancel</a><button type="submit" class="button-primary"><x-icon name="check" /><span>{{ $student->exists ? 'Save student' : 'Create student' }}</span></button></div></div>
</form>
</x-app-layout>
