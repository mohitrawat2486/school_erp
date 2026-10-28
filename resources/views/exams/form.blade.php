<x-app-layout :title="$record->exists ? 'Edit Exam' : 'Add Exam'">
<a href="{{ route('exams.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Exams</a><h1 class="mt-4 mb-6 font-bold">{{ $record->exists ? 'Edit Exam' : 'Add Exam' }}</h1><x-admin-feedback />
<section class="mb-6 rounded-xl border border-slate-200 bg-white p-5"><dl class="grid gap-4 sm:grid-cols-3">@foreach($context as $key=>$item)<div><dt class="text-sm capitalize text-slate-500">{{ $key==='year'?'Academic year':$key }}</dt><dd class="mt-2 font-semibold">{{ $item->name }}</dd></div>@endforeach</dl><p class="mt-4 text-sm text-slate-500">The selected context is fixed after creation.</p></section>
<form method="POST" action="{{ $record->exists ? route('exams.update',$record) : route('exams.store') }}" class="space-y-6">@csrf
@if($record->exists)
@method('PUT')
@else
@foreach($selection as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
@endif
<section class="rounded-xl border border-slate-200 bg-white p-6"><div class="grid gap-5 sm:grid-cols-2"><x-form-field name="name" label="Exam name" :value="$record->name" maxlength="100" required /><x-form-field name="start_date" label="Start date" type="date" :value="$record->start_date?->format('Y-m-d')" required /><x-form-field name="end_date" label="End date" type="date" :value="$record->end_date?->format('Y-m-d')" required /><p class="text-sm text-slate-500">Exam dates must be within the selected academic year.</p></div></section>
<div class="flex flex-wrap gap-3"><button class="button-primary rounded-lg bg-[#8b2f24] px-5 py-3 text-white">{{ $record->exists ? 'Save Exam' : 'Create Exam' }}</button><a href="{{ route('exams.index') }}" class="rounded-lg border border-slate-300 bg-white px-5 py-3">Cancel</a></div></form></x-app-layout>
