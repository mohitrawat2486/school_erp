<x-app-layout :title="$student->fullName()" :full-width="true">
<a href="{{ route('students.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Students</a>
<div class="mt-4 mb-6 flex flex-wrap items-center justify-between gap-4"><div><h1 class="text-3xl font-bold">{{ $student->fullName() }}</h1><p class="mt-2 text-slate-500">{{ $student->admission_number }} · {{ $student->status->value }}</p></div>
@can('update',$student)
<a href="{{ route('students.edit',$student) }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white">Edit student</a>
@endcan
</div><x-admin-feedback />
<section class="rounded-xl border border-slate-200 bg-white p-6">
@if($student->photo_path)
<img src="{{ route('students.photo',$student) }}" alt="Photo of {{ $student->fullName() }}" class="mb-6 h-32 w-32 rounded-xl object-cover">
@endif
<dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
@foreach(['admission_number'=>'Admission number','first_name'=>'First name','middle_name'=>'Middle name','last_name'=>'Last name','blood_group'=>'Blood group'] as $field=>$label)
<div><dt class="text-sm text-slate-500">{{ $label }}</dt><dd class="mt-2 break-words font-semibold">{{ $student->$field ?: '—' }}</dd></div>
@endforeach
<div><dt class="text-sm text-slate-500">Admission date</dt><dd class="mt-2 font-semibold">{{ $student->admission_date->format('d M Y') }}</dd></div><div><dt class="text-sm text-slate-500">Date of birth</dt><dd class="mt-2 font-semibold">{{ $student->date_of_birth?->format('d M Y') ?? '—' }}</dd></div><div><dt class="text-sm text-slate-500">Gender</dt><dd class="mt-2 font-semibold">{{ \App\Models\Student::GENDERS[$student->gender] ?? 'Not specified' }}</dd></div><div class="sm:col-span-2 lg:col-span-3"><dt class="text-sm text-slate-500">Address</dt><dd class="mt-2 whitespace-pre-wrap break-words">{{ $student->address ?: '—' }}</dd></div></dl></section>
@can('update',$student)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">{{ $student->status === \App\Enums\StudentStatus::Active ? 'Deactivate student' : 'Activate student' }}</summary><p class="mt-3 text-sm text-slate-600">{{ $student->status === \App\Enums\StudentStatus::Active ? 'Mark this student inactive. Admission records and history will be retained.' : 'Mark this student active again.' }}</p><form method="POST" action="{{ route($student->status === \App\Enums\StudentStatus::Active ? 'students.deactivate' : 'students.activate',$student) }}" class="mt-4">@csrf<button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">{{ $student->status === \App\Enums\StudentStatus::Active ? 'Confirm deactivation' : 'Confirm activation' }}</button></form></details>
@endcan
<section class="mt-6"><div class="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold">Enrollment history</h2>@can('create',\App\Models\StudentEnrollment::class)<a href="{{ route('enrollments.create',['student_id'=>$student->id]) }}" class="font-semibold text-[#8b2f24] underline">Enroll student</a>@endcan</div><x-enrollment-table :enrollments="$enrollments" /></section>
<section class="mt-6"><div class="mb-4 flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-semibold">Guardian contacts</h2>@can('update',$student)<a href="{{ route('students.guardians.index',$student) }}" class="font-semibold text-[#8b2f24] underline">Manage guardian contacts</a>@endcan</div><x-guardian-contact-table :links="$guardianLinks" :student="$student" /></section>
</x-app-layout>
