<x-app-layout :title="$student->exists ? 'Edit Student' : 'Add Student'">
<a href="{{ route('students.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Students</a><h1 class="mt-4 mb-6 text-3xl font-bold">{{ $student->exists ? 'Edit Student' : 'Add Student' }}</h1><x-admin-feedback />
<form action="{{ $student->exists ? route('students.update',$student) : route('students.store') }}" method="POST" enctype="multipart/form-data" class="max-w-5xl space-y-6">
@csrf
@if($student->exists)
@method('PUT')
@endif
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-5 text-lg font-semibold">Admission details</h2><div class="grid gap-5 sm:grid-cols-2">
<x-form-field name="admission_number" label="Admission number" :value="$student->admission_number" maxlength="50" required />
<x-form-field name="admission_date" label="Admission date" type="date" :value="$student->admission_date?->format('Y-m-d')" required />
</div></section>
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-5 text-lg font-semibold">Personal details</h2><div class="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
<x-form-field name="first_name" label="First name" :value="$student->first_name" maxlength="100" required />
<x-form-field name="middle_name" label="Middle name" :value="$student->middle_name" maxlength="100" />
<x-form-field name="last_name" label="Last name" :value="$student->last_name" maxlength="100" />
<div><label for="gender" class="mb-2 block text-sm font-semibold">Gender</label><select id="gender" name="gender" class="w-full rounded-lg border border-slate-300 bg-white p-3"><option value="">Not specified</option>@foreach(\App\Models\Student::GENDERS as $value=>$label)<option value="{{ $value }}" @selected(old('gender',$student->gender)===$value)>{{ $label }}</option>@endforeach</select></div>
<x-form-field name="date_of_birth" label="Date of birth" type="date" :value="$student->date_of_birth?->format('Y-m-d')" />
<div><label for="blood_group" class="mb-2 block text-sm font-semibold">Blood group</label><select id="blood_group" name="blood_group" class="w-full rounded-lg border border-slate-300 bg-white p-3"><option value="">Not specified</option>@foreach(\App\Models\Student::BLOOD_GROUPS as $group)<option value="{{ $group }}" @selected(old('blood_group',$student->blood_group)===$group)>{{ $group }}</option>@endforeach</select></div>
</div></section>
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-5 text-lg font-semibold">Address</h2><div class="mt-5"><label for="address" class="mb-2 block text-sm font-semibold">Address</label><textarea id="address" name="address" maxlength="2000" rows="3" class="w-full rounded-lg border border-slate-300 p-3">{{ old('address',$student->address) }}</textarea></div></section>
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="mb-5 text-lg font-semibold">Student photo</h2>
@if($student->photo_path)
<img src="{{ route('students.photo',$student) }}" alt="Current student photo" class="mb-4 h-28 w-28 rounded-lg object-cover">
@endif
<label for="photo" class="mb-2 block text-sm font-semibold">Upload photo</label><input id="photo" name="photo" type="file" accept="image/png,image/jpeg,image/webp" aria-describedby="photo-help" class="w-full rounded-lg border border-slate-300 p-3 text-sm"><p id="photo-help" class="mt-2 text-sm text-slate-500">PNG, JPEG or WebP; up to 2 MB and 2000 × 2000 pixels. Leave empty to keep the current photo.</p></section>
<div class="flex flex-wrap gap-3"><button class="rounded-lg bg-[#8b2f24] px-6 py-3 font-semibold text-white">{{ $student->exists ? 'Save student' : 'Create student' }}</button><a href="{{ $student->exists ? route('students.show',$student) : route('students.index') }}" class="rounded-lg border border-slate-300 bg-white px-6 py-3 font-semibold">Cancel</a></div>
</form></x-app-layout>
