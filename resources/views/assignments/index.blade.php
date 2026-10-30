@php
    $directory = $workspace['directory'] ?? [];
    $rowWorkspace = ['directory'=>$directory, 'list'=>$list];
    $reset = array_intersect_key($filters, array_flip(['teacher_id','academic_year_id'])) + ($directory ? ['directory'=>$directory] : []);
    $newYear = $year ?? $currentYear;
    $yearWritable = !$newYear || in_array($newYear->status->value, ['DRAFT','ACTIVE'], true);
    $createQuery = array_intersect_key($filters, array_flip(['teacher_id','class_id'])) + ($newYear && $yearWritable ? ['academic_year_id'=>$newYear->id] : []) + $rowWorkspace;
@endphp
<x-app-layout :title="$teacher ? $teacher->employee->fullName().' · Assignments' : 'Teacher Assignments'" :full-width="true">
@if($teacher && auth()->user()->can('teacher.view'))
<a href="{{ route('teachers.index',$directory) }}" class="workspace-back"><x-icon name="chevron-left" /><span>Back to Teachers</span></a>
@endif
<div data-list-sync="teacher-assignment-header" class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Teachers workspace</p><h1 class="mt-2 text-3xl font-bold">{{ $teacher ? $teacher->employee->fullName() : 'Teacher Assignments' }}</h1><p class="mt-2 text-slate-500">{{ $teacher ? $teacher->employee->employee_number.' · Teaching assignments' : 'Manage teaching responsibilities across your school.' }}</p></div>
@can('create', \App\Models\TeacherAssignment::class)
@if((!$teacher || $teacher->isEligible()) && $yearWritable)
<a href="{{ route('assignments.create',$createQuery) }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="plus" /><span>Add assignment</span></a>
@endif
@endcan
</div><x-admin-feedback /><x-teacher-workspace-tabs active="assignments" :directory="$directory" />
<div data-list-sync="teacher-assignment-scope">
@if($teacher)
<div class="teacher-scope-bar"><div><span class="teacher-scope-icon"><x-icon name="teacher" /></span><div><strong>Viewing assignments for {{ $teacher->employee->fullName() }}</strong><p>{{ $year ? $year->name : 'All academic years' }} · Use the filters below to review current responsibilities or history.</p></div></div>
@can('view',$teacher)<x-action-link :href="route('teachers.show',['teacher_profile'=>$teacher->id,...$workspace])" label="View profile" />@endcan
</div>
@if(!$teacher->isEligible())<p class="workspace-note">Activate the teacher profile and employee record before adding assignments. Existing assignments and history are available below.</p>@endif
@endif
@if($year && !in_array($year->status->value,['DRAFT','ACTIVE'],true))<p class="workspace-note">This academic year is read-only. Choose a draft or active year to add assignments.</p>@endif
</div>
<form method="GET" action="{{ route('assignments.index') }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 list-toolbar" data-list-filters>
<x-context-fields :values="$directory ? ['directory'=>$directory] : []" />
@foreach(['class_id','teacher_id'] as $field)
@if(isset($filters[$field]))<input type="hidden" name="{{ $field }}" value="{{ $filters[$field] }}">@endif
@endforeach
<div class="min-w-48 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">Search assignments</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Teacher, class or subject" class="w-full rounded-lg border border-slate-300 p-3"></div>
<div><label for="academic_year_id" class="mb-2 block text-sm font-semibold">Academic year</label><select id="academic_year_id" name="academic_year_id" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All years</option>@foreach($years as $option)<option value="{{ $option->id }}" @selected((string)($filters['academic_year_id'] ?? '') === (string)$option->id)>{{ $option->name }}</option>@endforeach</select></div>
@foreach(['assignment_type'=>\App\Enums\AssignmentType::cases(),'status'=>\App\Enums\TeacherAssignmentStatus::cases()] as $field=>$options)
<div><label for="{{ $field }}" class="mb-2 block text-sm font-semibold">{{ $field==='status' ? 'Status' : 'Type' }}</label><select id="{{ $field }}" name="{{ $field }}" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All</option>@foreach($options as $option)<option value="{{ $option->value }}" @selected(($filters[$field] ?? '')===$option->value)>{{ ucfirst(strtolower(str_replace('_',' ',$option->value))) }}</option>@endforeach</select></div>
@endforeach
<button class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="search" /><span>Filter</span></button><a data-list-sync="teacher-assignment-class-picker" href="{{ route('assignments.filter', array_intersect_key($filters,array_flip(['teacher_id','academic_year_id'])) + $rowWorkspace) }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Choose class</a><a data-list-sync="teacher-assignment-reset" href="{{ route('assignments.index',$reset) }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Reset</a></form>
<x-data-table :records="$assignments" title="Teaching responsibilities" icon="assignment"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Year</th>@if(!$teacher)<th class="p-4">Teacher</th>@endif<th class="p-4">Class / scope</th><th class="p-4">Type / subject</th><th class="p-4">Status</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($assignments as $assignment)
<tr class="border-t border-slate-200"><td class="p-4">{{ $assignment->academicYear->name }}</td>@if(!$teacher)<td class="p-4 font-semibold">{{ $assignment->teacher->employee->fullName() }}</td>@endif<td class="p-4">{{ $assignment->schoolClass->name }} / {{ $assignment->section?->name ?? 'Whole class' }}</td><td class="p-4">{{ ucfirst(strtolower(str_replace('_',' ',$assignment->assignment_type->value))) }}<br>{{ $assignment->subject?->name }}</td><td class="p-4"><x-status-badge :value="$assignment->status->value" /></td><td class="p-4"><x-action-link :href="route('assignments.show',['teacher_assignment'=>$assignment->id,...$rowWorkspace])" label="View" :aria-label="'View assignment for '.$assignment->teacher->employee->fullName()" /></td></tr>
@empty
<tr><td colspan="{{ $teacher ? 5 : 6 }}" class="p-8 text-center text-slate-500">No assignments match your filters.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
