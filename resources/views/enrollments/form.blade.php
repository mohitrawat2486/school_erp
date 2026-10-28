<x-app-layout :title="$enrollment->exists ? 'Edit Enrollment' : 'Enroll Student'">
<a href="{{ route('enrollments.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Enrollments</a><h1 class="mt-4 mb-6 font-bold">{{ $enrollment->exists ? 'Edit Enrollment' : 'Enroll Student' }}</h1><x-admin-feedback />
<section class="mb-6 rounded-xl border border-slate-200 bg-white p-6"><dl class="grid gap-5 sm:grid-cols-2">@foreach($context as $key=>$record)<div><dt class="text-sm capitalize text-slate-500">{{ $key==='year' ? 'Academic year' : $key }}</dt><dd class="mt-2 font-semibold">{{ $key==='student' ? $record->fullName().' · '.$record->admission_number : $record->name }}</dd></div>@endforeach</dl><p class="mt-5 text-sm text-slate-500">Student, year, class and section are fixed once saved. Enrollment history is retained.</p></section>
<form method="POST" action="{{ $enrollment->exists ? route('enrollments.update',$enrollment) : route('enrollments.store') }}" class="space-y-6">@csrf
@if($enrollment->exists)
@method('PUT')
@else
@foreach($selection as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
@endif
<section class="rounded-xl border border-slate-200 bg-white p-6"><div class="grid gap-5 sm:grid-cols-2"><x-form-field name="roll_number" label="Roll number" type="number" :value="$enrollment->roll_number" required /><x-form-field name="joined_at" label="Joined date" type="date" :value="$enrollment->joined_at?->format('Y-m-d')" required /></div><p class="mt-4 text-sm text-slate-500">Joined date must fall within the academic year and be on or after admission.</p></section>
<div class="flex flex-wrap gap-3"><button class="button-primary rounded-lg bg-[#8b2f24] px-5 py-3 text-white">{{ $enrollment->exists ? 'Save enrollment' : 'Confirm enrollment' }}</button><a href="{{ route('enrollments.index') }}" class="rounded-lg border border-slate-300 bg-white px-5 py-3">Cancel</a></div></form></x-app-layout>
