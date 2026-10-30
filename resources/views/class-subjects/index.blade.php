@php
 $catalog=$workspace['catalog']??[];
 $rowWorkspace=['catalog'=>$catalog,'mapping'=>$mapping];
 $selectedYear=$filterYear??$currentYear;
 $writable=!$selectedYear||in_array($selectedYear->status->value,['DRAFT','ACTIVE'],true);
 $reset=array_intersect_key($filters,array_flip(['subject_id','academic_year_id','class_id']))+($catalog?['catalog'=>$catalog]:[]);
@endphp
<x-app-layout :title="$filterSubject?$filterSubject->name.' · Classes':'Subjects by class'" :full-width="true">
@if($filterSubject)<a href="{{ route('subjects.index',$catalog) }}" class="workspace-back"><x-icon name="chevron-left" /><span>Back to Subjects</span></a>
@endif
<div data-list-sync="subject-classes-header" class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Subjects workspace</p><h1 class="mt-2 text-3xl font-bold">{{ $filterSubject?$filterSubject->name:'Subjects by class' }}</h1><p class="mt-2 text-slate-500">{{ $filterSubject?'Classes using this subject, with marks settings for each academic year.':'Review the subjects taught in each class and their marks settings.' }}</p></div>
@can('create', \App\Models\ClassSubject::class)
@if($filterSubject && $filterSubject->status->value==='ACTIVE' && $writable)
<a href="{{ route('subjects.classes.edit',['subject'=>$filterSubject->id,...($selectedYear?['academic_year_id'=>$selectedYear->id]:[]),...$rowWorkspace]) }}" class="button-primary rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white"><x-icon name="classes" /><span>Manage classes</span></a>
@elseif(!$filterSubject)
<a href="{{ route('subjects.index',$catalog) }}" class="button-primary rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white"><x-icon name="book" /><span>Choose a subject to assign</span></a>
@endif
@endcan
</div><x-admin-feedback /><x-subject-workspace-tabs active="classes" :catalog="$catalog" :year="$currentYear" />
<div data-list-sync="subject-classes-context">
@if($filterSubject)<div class="teacher-scope-bar"><div><span class="teacher-scope-icon"><x-icon name="book" /></span><div><strong>Classes for {{ $filterSubject->name }}</strong><p>{{ $filterYear?$filterYear->name:'All academic years' }}{{ $filterClass?' · '.$filterClass->name:'' }} · {{ $filterSubject->status->value==='ACTIVE'?'Subject available':'Subject inactive' }}</p></div></div><x-action-link :href="route('subjects.show',['subject'=>$filterSubject->id,...$rowWorkspace])" label="View subject" /></div>
@endif
@if($filterYear&&!in_array($filterYear->status->value,['DRAFT','ACTIVE'],true))<p class="workspace-note">This academic year is read-only. Select a draft or active year to change classes or marks settings.</p>
@endif
</div>
<form method="GET" action="{{ route('class-subjects.index') }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 list-toolbar" data-list-filters>
<x-context-fields :values="$catalog?['catalog'=>$catalog]:[]" />
@if($filterSubject)<input type="hidden" name="subject_id" value="{{ $filterSubject->id }}">
@endif
<div class="min-w-48 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">Search subjects or classes</label><input id="q" name="q" value="{{ $filters['q']??'' }}" maxlength="100" class="w-full rounded-lg border border-slate-300 p-3"></div>
<div><label for="academic_year_id" class="mb-2 block text-sm font-semibold">Academic year</label><select id="academic_year_id" name="academic_year_id" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All years</option>
@foreach($years as $option)<option value="{{ $option->id }}" @selected((string)($filters['academic_year_id']??'')===(string)$option->id)>{{ $option->name }}</option>
@endforeach</select></div>
<div><label for="class_id" class="mb-2 block text-sm font-semibold">Class</label><select id="class_id" name="class_id" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All classes</option>
@foreach($classes as $option)<option value="{{ $option->id }}" @selected((string)($filters['class_id']??'')===(string)$option->id)>{{ $option->name }}</option>
@endforeach</select></div>
<div><label for="status" class="mb-2 block text-sm font-semibold">Class use</label><select id="status" name="status" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All</option>
@foreach(\App\Enums\ClassSubjectStatus::cases() as $status)<option value="{{ $status->value }}" @selected(($filters['status']??'')===$status->value)>{{ ucfirst(strtolower($status->value)) }}</option>
@endforeach</select></div>
<button class="button-primary rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white"><x-icon name="search" /><span>Filter</span></button><a data-list-sync="subject-classes-reset" href="{{ route('class-subjects.index',$reset) }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Reset</a>
</form>
<x-data-table :records="$allocations" title="Subjects taught in classes" icon="books"><table class="w-full text-left text-sm subject-mapping-table"><thead><tr><th>Year</th><th>Class</th>
@if(!$filterSubject)<th>Subject</th>
@endif<th>Maximum</th><th>Passing</th><th>Class use</th><th>Actions</th></tr></thead><tbody>
@forelse($allocations as $allocation)
<tr><td>{{ $allocation->academicYear->name }}@if($allocation->academicYear->status->value!=='ACTIVE')<span class="subject-code">{{ ucfirst(strtolower($allocation->academicYear->status->value)) }} year</span>@endif</td><td class="font-semibold">{{ $allocation->schoolClass->name }}@if($allocation->schoolClass->status->value!=='ACTIVE')<span class="subject-code">Inactive class</span>@endif</td>
@if(!$filterSubject)<td>{{ $allocation->subject->name }}@if($allocation->subject->status->value!=='ACTIVE')<span class="subject-code">Subject inactive</span>@endif</td>
@endif<td>{{ $allocation->maximumMarks()??'Not configured' }}</td><td>{{ $allocation->passing_marks??'Not configured' }}</td><td><x-status-badge :value="$allocation->status->value" /></td><td><div class="table-actions"><x-action-link :href="route('class-subjects.show',['class_subject'=>$allocation->id,...$rowWorkspace])" label="View" />
@can('update',$allocation)
@if($allocation->isEditable())<x-action-link :href="route('class-subjects.edit',['class_subject'=>$allocation->id,...$rowWorkspace])" label="Marks settings" icon="pencil" />
@endif
@endcan</div></td></tr>
@empty<tr><td colspan="{{ $filterSubject?6:7 }}">No classes match your filters. Choose an academic year or assign this subject to classes.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
