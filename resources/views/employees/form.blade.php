<x-app-layout :title="$employee->exists ? 'Edit Employee' : 'Add Employee'" :full-width="true">
<a href="{{ route('employees.index') }}" class="workspace-back"><x-icon name="chevron-left" />Employees</a>
<div class="student-form-header">
    <div><p class="student-form-eyebrow">Employees</p><h1>{{ $employee->exists ? 'Edit Employee' : 'Add Employee' }}</h1><p>{{ $employee->exists ? 'Keep personal, employment and contact details up to date.' : 'Add a staff member with their employment and contact details.' }}</p></div>
    <p class="student-required-note"><span class="student-required">*</span> Required fields</p>
</div>
<x-admin-feedback />
<form action="{{ $employee->exists ? route('employees.update', $employee) : route('employees.store') }}" method="POST" enctype="multipart/form-data" class="employee-editor" data-employee-editor>
    @csrf
    @if($employee->exists)@method('PUT')@endif
    <div class="employee-editor-grid">
        <div class="employee-editor-column">
            <section class="student-form-card" aria-labelledby="employee-personal-heading">
                <div class="student-card-heading"><span class="student-card-icon"><x-icon name="users" /></span><div><h2 id="employee-personal-heading">Personal details</h2><p>Start with the employee’s name and basic information.</p></div></div>
                <div class="student-field-grid">
                    <x-student-form-field name="first_name" label="First name" :value="$employee->first_name" maxlength="100" autocomplete="given-name" required />
                    <x-student-form-field name="last_name" label="Last name" :value="$employee->last_name" maxlength="100" autocomplete="family-name" />
                    <x-student-form-field name="middle_name" label="Middle name" :value="$employee->middle_name" maxlength="100" autocomplete="additional-name" />
                    <div class="student-field">
                        <label for="gender">Gender</label>
                        <select id="gender" name="gender" autocomplete="sex" aria-describedby="gender-feedback" @if($errors->has('gender')) aria-invalid="true" @endif>
                            <option value="">Not specified</option>
                            @foreach(\App\Models\Employee::GENDERS as $value => $label)
                                <option value="{{ $value }}" @selected(old('gender', $employee->gender) === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <div id="gender-feedback">@error('gender')<p class="student-field-error">{{ $message }}</p>@enderror</div>
                    </div>
                    <x-student-form-field name="date_of_birth" label="Date of birth" type="date" :value="$employee->date_of_birth?->format('Y-m-d')" :max="today()->toDateString()" autocomplete="bday" />
                    <x-student-form-field name="aadhaar_number" label="Aadhaar number" required :value="$employee->aadhaar_number" maxlength="12" minlength="12" pattern="[0-9]{12}" inputmode="numeric" autocomplete="off" hint="Identification: enter the 12-digit number without spaces." />
                </div>
            </section>
            <section class="student-form-card" aria-labelledby="employee-employment-heading">
                <div class="student-card-heading"><span class="student-card-icon"><x-icon name="employees" /></span><div><h2 id="employee-employment-heading">Employment details</h2><p>Identify the employee and their role at the school.</p></div></div>
                <div class="student-field-grid">
                    <x-student-form-field name="employee_number" label="Employee number" :value="$employee->employee_number" maxlength="50" hint="Use the unique number from your staff register." required />
                    <div class="student-field">
                        <label for="employee_type">Employee type <span class="student-required" aria-hidden="true">*</span></label>
                        <select id="employee_type" name="employee_type" required aria-describedby="employee_type-feedback" @if($errors->has('employee_type')) aria-invalid="true" @endif>
                            @foreach(\App\Enums\EmployeeType::cases() as $type)
                                <option value="{{ $type->value }}" @selected(old('employee_type', $employee->employee_type->value) === $type->value)>{{ $type->label() }}</option>
                            @endforeach
                        </select>
                        <div id="employee_type-feedback">@error('employee_type')<p class="student-field-error">{{ $message }}</p>@enderror</div>
                    </div>
                    <x-student-form-field name="joining_date" label="Joining date" type="date" :value="$employee->joining_date?->format('Y-m-d')" required />
                    <x-student-form-field name="designation" label="Designation" :value="$employee->designation" autocomplete="organization-title" />
                    <x-student-form-field name="department" label="Department" :value="$employee->department" />
                </div>
            </section>
        </div>
        <div class="employee-editor-column">
            <section class="student-form-card" aria-labelledby="employee-contact-heading">
                <div class="student-card-heading"><span class="student-card-icon"><x-icon name="phone" /></span><div><h2 id="employee-contact-heading">Contact information <span class="student-optional">Optional</span></h2><p>Add the best ways to reach this employee.</p></div></div>
                <div class="student-field-grid">
                    <div class="employee-field-wide"><x-student-form-field name="email" label="Email address" type="email" :value="$employee->email" autocomplete="email" /></div>
                    <x-student-form-field name="phone" label="Phone number" type="tel" :value="$employee->phone" maxlength="30" autocomplete="tel" />
                    <x-student-form-field name="alternate_phone" label="Alternate phone" type="tel" :value="$employee->alternate_phone" maxlength="30" />
                    <div class="student-field employee-field-wide">
                        <label for="address">Address</label>
                        <textarea id="address" name="address" maxlength="2000" rows="3" autocomplete="street-address" aria-describedby="address-feedback" @if($errors->has('address')) aria-invalid="true" @endif>{{ old('address', $employee->address) }}</textarea>
                        <div id="address-feedback">@error('address')<p class="student-field-error">{{ $message }}</p>@enderror</div>
                    </div>
                </div>
            </section>
            <section class="student-form-card" aria-labelledby="employee-photo-heading">
                <div class="student-card-heading"><span class="student-card-icon"><x-icon name="camera" /></span><div><h2 id="employee-photo-heading">Employee photo <span class="student-optional">Optional</span></h2><p>A clear portrait makes staff easier to recognise.</p></div></div>
                <div class="employee-photo-upload" data-employee-photo>
                    <div class="employee-photo-frame">
                        <span data-employee-photo-current>@if($employee->photo_path)<img src="{{ route('employees.photo', $employee) }}" alt="Current employee photo" width="88" height="88">@else<x-icon name="users" />@endif</span>
                        <img data-employee-photo-preview hidden alt="Selected employee photo preview" width="88" height="88">
                    </div>
                    <div class="student-field">
                        <label for="photo">{{ $employee->photo_path ? 'Replace photo' : 'Upload photo' }}</label>
                        <input id="photo" name="photo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="photo-feedback" @if($errors->has('photo')) aria-invalid="true" @endif>
                        <div id="photo-feedback"><p class="student-field-hint">JPEG, PNG or WebP, up to 2 MB and 2000 × 2000 pixels.@if($employee->photo_path) Leave empty to keep the current photo.@endif</p>@error('photo')<p class="student-field-error">{{ $message }}</p>@enderror</div>
                    </div>
                </div>
            </section>
        </div>
    </div>
    <div class="student-form-actions"><p>Review the details before {{ $employee->exists ? 'saving your changes' : 'creating the employee' }}.</p><div><a href="{{ $employee->exists ? route('employees.show', $employee) : route('employees.index') }}" class="student-cancel">Cancel</a><button type="submit" class="button-primary"><x-icon name="check" /><span>{{ $employee->exists ? 'Save employee' : 'Create employee' }}</span></button></div></div>
</form>
</x-app-layout>
