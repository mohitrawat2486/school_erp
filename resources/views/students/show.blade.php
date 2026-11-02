<x-app-layout :title="$student->fullName()" :full-width="true">
@php
    $currentGuardians = $guardianLinks->filter(fn ($link) => $link->status->value === 'ACTIVE');
    $previousGuardians = $guardianLinks->filter(fn ($link) => $link->status->value !== 'ACTIVE');
@endphp
<a href="{{ route('students.index') }}" class="workspace-back">← Students</a>
<x-admin-feedback />
<section class="student-profile-hero" aria-labelledby="student-profile-name">
    <div class="student-profile-identity">
        <div class="student-profile-avatar">@if($student->photo_path)<img src="{{ route('students.photo', $student) }}" alt="Photo of {{ $student->fullName() }}" width="96" height="96">@else<span aria-hidden="true">{{ mb_substr($student->first_name, 0, 1).mb_substr($student->last_name ?? '', 0, 1) }}</span>@endif</div>
        <div class="student-profile-intro"><p class="student-form-eyebrow">Student profile</p><div class="student-profile-title"><h1 id="student-profile-name">{{ $student->fullName() }}</h1><x-status-badge :value="$student->status->value" /></div><p class="student-profile-admission">Admission number <strong>{{ $student->admission_number }}</strong></p></div>
    </div>
    @can('update', $student)<x-action-link :href="route('students.edit', $student)" label="Edit student" icon="pencil" :primary="true" />@endcan
    <dl class="student-profile-highlights">
        <div><dt><x-icon name="classes" />Current class</dt><dd>{{ $currentEnrollment?->schoolClass?->name ?? 'Not enrolled' }}@if($currentEnrollment?->section)<span>Section {{ $currentEnrollment->section->name }}</span>@endif</dd></div>
        <div><dt><x-icon name="calendar" />Academic year</dt><dd>{{ $currentYear?->name ?? 'No current year set' }}</dd></div>
        <div><dt><x-icon name="assignment" />Admission date</dt><dd>{{ $student->admission_date->format('d M Y') }}</dd></div>
    </dl>
    @if($currentDeparture)
    <div class="student-profile-departure"><div><x-status-badge :value="$currentDeparture->action->value === 'TRANSFER' ? 'TRANSFERRED' : 'WITHDRAWN'" :label="$currentDeparture->action->value === 'TRANSFER' ? 'Transferred' : 'Withdrawn'" />@if($currentDeparture->departure_date)<span>{{ $currentDeparture->departure_date->format('d M Y') }}</span>@endif</div><p>{{ $currentDeparture->notes }}</p>@if($currentDeparture->destination_school)<p>Destination school: {{ $currentDeparture->destination_school }}</p>@endif</div>
    @endif
</section>
<div class="student-profile-grid">
    <section class="student-profile-card" aria-labelledby="student-personal-heading">
        <div class="student-profile-card-heading"><div><span class="student-card-icon"><x-icon name="users" /></span><h2 id="student-personal-heading">Student details</h2></div></div>
        <dl class="student-personal-details">
            @foreach(['first_name' => 'First name', 'middle_name' => 'Middle name', 'last_name' => 'Last name'] as $field => $label)<div><dt>{{ $label }}</dt><dd>{{ $student->$field ?: 'Not recorded' }}</dd></div>@endforeach
            <div><dt>Date of birth</dt><dd>{{ $student->date_of_birth?->format('d M Y') ?? 'Not recorded' }}</dd></div>
            <div><dt>Gender</dt><dd>{{ \App\Models\Student::GENDERS[$student->gender] ?? 'Not specified' }}</dd></div>
            <div><dt>Blood group</dt><dd>{{ $student->blood_group ?: 'Not recorded' }}</dd></div>
            <div><dt>Aadhaar number</dt><dd>{{ $student->aadhaar_number ? 'XXXX XXXX '.substr($student->aadhaar_number, -4) : 'Not recorded' }}</dd></div>
        </dl>
        <div class="student-profile-address"><h3><x-icon name="location" />Home address</h3><p>{{ $student->address ?: 'No address recorded.' }}</p></div>
    </section>
    <section class="student-profile-card" aria-labelledby="student-contacts-heading" data-student-guardian-contacts>
        <div class="student-profile-card-heading"><div><span class="student-card-icon"><x-icon name="users" /></span><h2 id="student-contacts-heading">Guardian contacts <span class="student-optional">{{ $currentGuardians->count() }}</span></h2></div>@can('update', $student)<x-action-link :href="route('students.edit', $student).'#guardian-heading'" label="Edit guardians" icon="pencil" />@endcan</div>
        @if($currentGuardians->isNotEmpty())<x-student-guardian-list :links="$currentGuardians" />@else<div class="student-guardian-empty"><x-icon name="users" /><h3>No guardian contacts added</h3><p>Parent and emergency contact details will appear here.</p></div>@endif
        @if($previousGuardians->isNotEmpty())<details class="student-previous-guardians"><summary>Previous guardian links <span>{{ $previousGuardians->count() }}</span></summary><p>These contacts are retained as history and are no longer linked to this student.</p><x-student-guardian-list :links="$previousGuardians" :historical="true" /></details>@endif
    </section>
</div>
<section class="student-profile-enrollments" aria-labelledby="student-enrollment-heading"><div class="student-profile-section-heading"><div><h2 id="student-enrollment-heading">Enrollment history</h2><p>Academic years, classes and sections for this student.</p></div>@can('create', \App\Models\StudentEnrollment::class)<x-action-link :href="route('students.edit', $student).'#enrollment-heading'" label="Enroll student" icon="plus" :primary="true" />@endcan</div><x-enrollment-table :enrollments="$enrollments" /></section>
@can('update', $student)
<details class="student-profile-status"><summary>{{ $student->status === \App\Enums\StudentStatus::Active ? 'Deactivate student' : 'Activate student' }}</summary><p>{{ $student->status === \App\Enums\StudentStatus::Active ? 'Mark this student inactive. Admission records and history will be retained.' : 'Mark this student active again.' }}</p><form method="POST" action="{{ route($student->status === \App\Enums\StudentStatus::Active ? 'students.deactivate' : 'students.activate', $student) }}">@csrf<button>{{ $student->status === \App\Enums\StudentStatus::Active ? 'Confirm deactivation' : 'Confirm activation' }}</button></form></details>
@endcan
</x-app-layout>
