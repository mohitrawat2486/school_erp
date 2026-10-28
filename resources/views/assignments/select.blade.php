@php($label = match($step) {'year'=>'academic year','teacher'=>'teacher','class'=>'class','section'=>'section','subject'=>'subject'})
@php($field = match($step) {'year'=>'academic_year_id','teacher'=>'teacher_id','class'=>'class_id','section'=>'section_id','subject'=>'subject_id'})
<x-app-layout :title="'Choose '.ucfirst($label)" :full-width="true"><a href="{{ route('assignments.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Teacher Assignments</a><h1 class="mt-4 mb-6 text-3xl font-bold">Choose {{ $label }}</h1><x-admin-feedback />
<form method="GET" action="{{ route($creating ? 'assignments.create' : 'assignments.filter') }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 list-toolbar">@foreach($selection as $key=>$value)<input type="hidden" name="{{ $key }}" value="{{ $value }}">@endforeach
<div class="min-w-48 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">Search {{ $label }}</label><input id="q" name="q" value="{{ $q }}" maxlength="100" class="w-full rounded-lg border border-slate-300 p-3"></div><button class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="search" /><span>Search</span></button></form>
@if($step==='section')
<a href="{{ route('assignments.create',[...$selection,'scope'=>'ALL']) }}" class="mb-5 inline-block rounded-lg border border-[#8b2f24] bg-white px-5 py-3 font-semibold text-[#8b2f24]">Whole class — all active sections</a>
@endif
@if(!$creating && $step==='class')
<a href="{{ route('assignments.index',$selection) }}" class="mb-5 inline-block font-semibold text-[#8b2f24] underline">View all classes for this year</a>
@endif
<x-data-table :records="$items" title="Available records" icon="assignment"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Name</th><th class="p-4">Reference</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($items as $item)
<tr class="border-t border-slate-200"><td class="p-4 font-semibold">{{ $step==='teacher' ? $item->employee->fullName() : $item->name }}</td><td class="p-4">{{ match($step) {'teacher'=>$item->employee->employee_number,'year'=>$item->start_date->format('d M Y').' – '.$item->end_date->format('d M Y'),'section'=>'Section',default=>$item->code} }}</td><td class="p-4"><a href="{{ route($creating ? 'assignments.create' : 'assignments.filter',[...$selection,$field=>$item->id,...($step==='section' ? ['scope'=>'SECTION'] : [])]) }}" class="font-semibold text-[#8b2f24] underline table-action"><span>Select</span><x-icon name="chevron-right" /></a></td></tr>
@empty
<tr><td colspan="3" class="p-8 text-center text-slate-500">No eligible records match your search.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
