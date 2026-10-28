<x-app-layout :title="$profile->exists ? 'Edit Teacher Profile' : 'Add Teacher Profile'">
<a href="{{ $profile->exists ? route('teachers.show',$profile) : route('teachers.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Teachers</a><h1 class="mt-4 mb-6 text-3xl font-bold">{{ $profile->exists ? 'Edit Teacher Profile' : 'Add Teacher Profile' }}</h1><x-admin-feedback />
<section class="mb-6 max-w-4xl rounded-xl border border-slate-200 bg-white p-6"><h2 class="text-lg font-semibold">{{ $employee->fullName() }}</h2><p class="mt-2 text-sm text-slate-500">{{ $employee->employee_number }} · {{ $employee->employee_type->label() }}</p>
@if(!$profile->exists)
<a href="{{ route('teachers.create') }}" class="mt-3 inline-block text-sm font-semibold text-[#8b2f24] underline">Choose another employee</a>
@endif
</section>
<form method="POST" action="{{ $profile->exists ? route('teachers.update',$profile) : route('teachers.store') }}" class="max-w-4xl space-y-5 rounded-xl border border-slate-200 bg-white p-6">
@csrf
@if($profile->exists)
@method('PUT')
@else
<input type="hidden" name="employee_id" value="{{ $employee->id }}">
@endif
<div><label for="qualification" class="mb-2 block text-sm font-semibold">Qualifications</label><textarea id="qualification" name="qualification" maxlength="1000" rows="4" class="w-full rounded-lg border border-slate-300 p-3">{{ old('qualification',$profile->qualification) }}</textarea></div>
<x-employee-field name="specialization" label="Specialization" :value="$profile->specialization" />
<div><label for="experience" class="mb-2 block text-sm font-semibold">Experience (years)</label><input id="experience" name="experience" type="number" inputmode="decimal" step="0.01" min="0" max="99.99" value="{{ old('experience',$profile->experience) }}" class="w-full rounded-lg border border-slate-300 p-3"><p class="mt-2 text-sm text-slate-500">Leave empty if experience is not yet recorded.</p></div>
<div class="flex flex-wrap gap-3"><button class="rounded-lg bg-[#8b2f24] px-6 py-3 font-semibold text-white">{{ $profile->exists ? 'Save teacher profile' : 'Create teacher profile' }}</button><a href="{{ $profile->exists ? route('teachers.show',$profile) : route('teachers.index') }}" class="rounded-lg border border-slate-300 px-6 py-3 font-semibold">Cancel</a></div>
</form></x-app-layout>
