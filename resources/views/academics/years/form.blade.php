<x-app-layout :title="$year->exists ? 'Edit Academic Year' : 'Add Academic Year'">
<a href="{{ route('academic-years.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Academic Years</a><h1 class="mt-4 text-3xl font-bold">{{ $year->exists ? 'Edit Academic Year' : 'Add Academic Year' }}</h1><p class="mt-2 text-slate-500">New years start as drafts. Dates and names become read-only after activation.</p>
@if($errors->any())
<div role="alert" class="mt-5 rounded-lg bg-red-50 p-4 text-red-800"><p class="font-semibold">Please review your entry.</p><ul class="mt-2 list-inside list-disc">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
@endif
<form action="{{ $year->exists ? route('academic-years.update',$year) : route('academic-years.store') }}" method="POST" class="mt-6 max-w-3xl space-y-5 rounded-xl border border-slate-200 bg-white p-6 shadow-sm">
@csrf
@if($year->exists)
@method('PUT')
@endif
<div><label for="name" class="mb-2 block text-sm font-semibold">Academic year name</label><input id="name" name="name" value="{{ old('name',$year->name) }}" maxlength="100" required class="w-full rounded-lg border border-slate-300 p-3" aria-describedby="name-help"><p id="name-help" class="mt-2 text-sm text-slate-500">Use your school's session name. Each name can be used once per school.</p></div>
<div class="grid gap-5 sm:grid-cols-2"><div><label for="start_date" class="mb-2 block text-sm font-semibold">Start date</label><input id="start_date" name="start_date" type="date" value="{{ old('start_date',$year->start_date?->format('Y-m-d')) }}" required class="w-full rounded-lg border border-slate-300 p-3"></div><div><label for="end_date" class="mb-2 block text-sm font-semibold">End date</label><input id="end_date" name="end_date" type="date" value="{{ old('end_date',$year->end_date?->format('Y-m-d')) }}" required class="w-full rounded-lg border border-slate-300 p-3"></div></div>
<div class="flex flex-wrap gap-3"><button class="rounded-lg bg-[#8b2f24] px-6 py-3 font-semibold text-white hover:bg-[#692018]">{{ $year->exists ? 'Save changes' : 'Create draft' }}</button><a href="{{ route('academic-years.index') }}" class="rounded-lg border border-slate-300 px-6 py-3 font-semibold">Cancel</a></div>
</form></x-app-layout>
