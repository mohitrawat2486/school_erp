<x-app-layout :title="$section->exists ? 'Edit Section' : 'Add Section'">
<a href="{{ route('sections.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Sections</a><h1 class="mt-4 mb-6 text-3xl font-bold">{{ $section->exists ? 'Edit Section' : 'Add Section' }}</h1><x-admin-feedback />
<section class="mb-6 max-w-3xl rounded-xl border border-slate-200 bg-white p-6"><h2 class="text-lg font-semibold">Class: {{ $schoolClass->name }}</h2><p class="mt-2 text-sm text-slate-500">{{ $schoolClass->code }}</p>
@if(!$section->exists)
<a href="{{ route('sections.create') }}" class="mt-3 inline-block text-sm font-semibold text-[#8b2f24] underline">Choose another class</a>
@endif
</section>
<form method="POST" action="{{ $section->exists ? route('sections.update',$section) : route('sections.store') }}" class="max-w-3xl space-y-5 rounded-xl border border-slate-200 bg-white p-6">
@csrf
@if($section->exists)
@method('PUT')
@else
<input type="hidden" name="class_id" value="{{ $schoolClass->id }}">
@endif
<x-employee-field name="name" label="Section name" :value="$section->name" maxlength="50" required />
<p class="text-sm text-slate-500">For example, A, B or C. Each section name is unique within its class.</p>
<div><label for="capacity" class="mb-2 block text-sm font-semibold">Capacity</label><input id="capacity" name="capacity" type="number" inputmode="numeric" step="1" min="1" max="65535" value="{{ old('capacity',$section->capacity) }}" aria-describedby="capacity-help" class="w-full rounded-lg border border-slate-300 p-3"><p id="capacity-help" class="mt-2 text-sm text-slate-500">Enter a positive number of students, or leave empty if capacity is not yet set.</p></div>
<div class="flex flex-wrap gap-3"><button class="rounded-lg bg-[#8b2f24] px-6 py-3 font-semibold text-white">{{ $section->exists ? 'Save section' : 'Create section' }}</button><a href="{{ $section->exists ? route('sections.show',$section) : route('sections.index') }}" class="rounded-lg border border-slate-300 px-6 py-3 font-semibold">Cancel</a></div>
</form></x-app-layout>
