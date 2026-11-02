@props(['profile', 'employee' => null, 'large' => false])
@php
    $employee = $employee ?? $profile->employee;
    $photoUrl = null;
    if ($employee->photo_path) {
        if ($profile->exists) {
            $photoUrl = route('teachers.photo', $profile);
        } elseif (auth()->user()->can('view', $employee)) {
            $photoUrl = route('employees.photo', $employee);
        }
    }
@endphp
<span {{ $attributes->class(['teacher-avatar', 'teacher-avatar--large' => $large]) }}>
    @if($photoUrl)
        <img src="{{ $photoUrl }}" alt="Photo of {{ $employee->fullName() }}" width="{{ $large ? 96 : 40 }}" height="{{ $large ? 96 : 40 }}" loading="lazy">
    @else
        <span class="teacher-initials" aria-hidden="true">{{ mb_substr($employee->first_name, 0, 1).mb_substr($employee->last_name ?? '', 0, 1) }}</span>
    @endif
</span>
