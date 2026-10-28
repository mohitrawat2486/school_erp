<x-app-layout title="Academic Year">
<a href="{{ route('academic-years.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Academic Years</a><h1 class="mt-4 text-3xl font-bold">{{ $year->name }}</h1>
@if(session('status'))
<div role="status" class="mt-5 rounded-lg bg-green-50 p-4 text-green-800">{{ session('status') }}</div>
@endif
@if($errors->any())
<div role="alert" class="mt-5 rounded-lg bg-red-50 p-4 text-red-800">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
@endif
<section class="mt-6 rounded-xl border border-slate-200 bg-white p-6 shadow-sm"><dl class="grid gap-6 sm:grid-cols-2 lg:grid-cols-4"><div><dt class="text-sm text-slate-500">Start date</dt><dd class="mt-2 font-semibold">{{ $year->start_date->format('d M Y') }}</dd></div><div><dt class="text-sm text-slate-500">End date</dt><dd class="mt-2 font-semibold">{{ $year->end_date->format('d M Y') }}</dd></div><div><dt class="text-sm text-slate-500">Status</dt><dd class="mt-2 font-semibold">{{ $year->status->value }}</dd></div><div><dt class="text-sm text-slate-500">Current academic year</dt><dd class="mt-2 font-semibold">{{ $year->is_current ? 'Yes' : 'No' }}</dd></div></dl></section>
<div class="mt-6 space-y-4">
@if($year->status === \App\Enums\AcademicYearStatus::Draft)
@can('update',$year)
<a href="{{ route('academic-years.edit',$year) }}" class="inline-block rounded-lg border border-slate-300 bg-white px-5 py-3 font-semibold">Edit draft</a>
@endcan
@endif
@if(in_array($year->status,[\App\Enums\AcademicYearStatus::Draft,\App\Enums\AcademicYearStatus::Active]) && !$year->is_current)
@can('activate',$year)
<section class="rounded-xl border border-slate-200 bg-white p-6"><h2 class="font-semibold">{{ $year->status === \App\Enums\AcademicYearStatus::Draft ? 'Activate this year' : 'Make this year current' }}</h2><p class="mt-2 text-sm text-slate-600">This becomes the current year for your school. Any previous current year stays active and keeps its records.</p><form method="POST" action="{{ route('academic-years.activate',$year) }}" class="mt-4">@csrf<button class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white">{{ $year->status === \App\Enums\AcademicYearStatus::Draft ? 'Activate & make current' : 'Make current' }}</button></form></section>
@endcan
@endif
@if($year->status === \App\Enums\AcademicYearStatus::Active)
@can('close',$year)
<details class="rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">Close academic year</summary><p class="mt-3 text-sm text-slate-600">Closing is permanent. The year becomes read-only and, if current, is no longer selected. Choose another year separately.</p><form method="POST" action="{{ route('academic-years.close',$year) }}" class="mt-4">@csrf<button class="rounded-lg border border-red-300 px-5 py-3 font-semibold text-red-800">Confirm close year</button></form></details>
@endcan
@endif
@if($year->status === \App\Enums\AcademicYearStatus::Closed)
@can('archive',$year)
<details class="rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">Archive academic year</summary><p class="mt-3 text-sm text-slate-600">Archive this closed year while retaining its history. Archived years cannot be reopened.</p><form method="POST" action="{{ route('academic-years.archive',$year) }}" class="mt-4">@csrf<button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Confirm archive year</button></form></details>
@endcan
@endif
@if($year->status === \App\Enums\AcademicYearStatus::Draft)
@can('delete',$year)
<details class="rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold text-red-800">Remove draft</summary><p class="mt-3 text-sm text-slate-600">Remove this draft from the list. Its name stays reserved and its audit history is retained.</p><form method="POST" action="{{ route('academic-years.destroy',$year) }}" class="mt-4">@csrf @method('DELETE')<button class="rounded-lg border border-red-300 px-5 py-3 font-semibold text-red-800">Confirm remove draft</button></form></details>
@endcan
@endif
</div></x-app-layout>
