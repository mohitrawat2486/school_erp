<x-app-layout :title="$allocation->exists ? 'Marks settings' : 'Assign subject'"><a href="{{ route('class-subjects.index',($workspace['mapping']??['subject_id'=>$subject->id,'academic_year_id'=>$year->id])+array_intersect_key($workspace,['catalog'=>1])) }}" class="text-sm font-semibold text-[#8b2f24]">← Subject classes</a><h1 class="mt-4 mb-6 text-3xl font-bold">{{ $allocation->exists ? 'Marks settings' : 'Assign subject' }}</h1><x-admin-feedback />
<section class="mb-6 max-w-4xl rounded-xl border border-slate-200 bg-white p-6"><h2 class="text-lg font-semibold">{{ $subject->name }} ({{ $subject->code }})</h2><p class="mt-2 text-slate-500">{{ $year->name }} · {{ $schoolClass->name }}</p></section>
<form method="POST" action="{{ $allocation->exists ? route('class-subjects.update',$allocation) : route('class-subjects.store') }}" class="max-w-4xl space-y-5 rounded-xl border border-slate-200 bg-white p-6">@csrf<x-context-fields :values="$workspace" />
@if($allocation->exists)
@method('PUT')
@else
<input type="hidden" name="academic_year_id" value="{{ $year->id }}"><input type="hidden" name="class_id" value="{{ $schoolClass->id }}"><input type="hidden" name="subject_id" value="{{ $subject->id }}">
@endif
<p class="text-sm text-slate-500">Leave all marks fields blank to configure them later. Passing marks require a maximum and cannot exceed the combined total.</p>
<div class="grid gap-5 sm:grid-cols-3">
@foreach(['theory_max_marks'=>'Theory maximum','practical_max_marks'=>'Practical maximum','passing_marks'=>'Passing marks'] as $field=>$label)
<div><label for="{{ $field }}" class="mb-2 block text-sm font-semibold">{{ $label }}</label><input id="{{ $field }}" name="{{ $field }}" type="number" step="0.01" min="0" max="9999.99" value="{{ old($field,$allocation->$field) }}" class="w-full rounded-lg border border-slate-300 p-3"></div>
@endforeach
</div><button class="rounded-lg bg-[#8b2f24] px-6 py-3 font-semibold text-white">{{ $allocation->exists ? 'Save marks settings' : 'Assign subject' }}</button>
</form></x-app-layout>
