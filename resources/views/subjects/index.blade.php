<x-app-layout title="Subjects" :full-width="true">
<div data-list-sync="subject-catalog-header" class="list-page-header mb-6 flex flex-wrap items-center justify-between gap-4"><div><p class="text-sm font-semibold uppercase tracking-widest text-[#8b2f24]">Academics</p><h1 class="mt-2 text-3xl font-bold">Subjects</h1><p class="mt-2 text-slate-500">Manage subjects and the classes where they are taught.</p></div>
@can('create', \App\Models\Subject::class)
<a href="{{ route('subjects.create',['catalog'=>$catalog]) }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="plus" /><span>Add subject</span></a>
@endcan
</div><x-admin-feedback /><x-subject-workspace-tabs :catalog="$catalog" :year="$year" />
@if($year)<p class="workspace-note">Class counts show active assignments for <strong>{{ $year->name }}</strong>.</p>@endif
<form method="GET" action="{{ route('subjects.index') }}" class="mb-6 flex flex-wrap items-end gap-4 rounded-xl border border-slate-200 bg-white p-5 list-toolbar"><div class="min-w-48 flex-1"><label for="q" class="mb-2 block text-sm font-semibold">Search subjects</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" maxlength="100" placeholder="Name or code" class="w-full rounded-lg border border-slate-300 p-3"></div>
@foreach(['status'=>\App\Enums\SubjectStatus::cases(),'type'=>\App\Enums\SubjectType::cases()] as $field=>$options)
<div><label for="{{ $field }}" class="mb-2 block text-sm font-semibold">{{ ucfirst($field) }}</label><select id="{{ $field }}" name="{{ $field }}" class="rounded-lg border border-slate-300 bg-white p-3"><option value="">All</option>@foreach($options as $option)<option value="{{ $option->value }}" @selected(($filters[$field] ?? '')===$option->value)>{{ ucfirst(strtolower($option->value)) }}</option>@endforeach</select></div>
@endforeach
<button class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white button-primary"><x-icon name="search" /><span>Filter</span></button><a href="{{ route('subjects.index') }}" class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Reset</a></form>
<x-data-table :records="$subjects" title="Subject catalog" icon="book"><table class="w-full text-left text-sm"><thead class="bg-slate-50"><tr><th class="p-4">Subject</th><th class="p-4">Type</th><th class="p-4">Classes</th><th class="p-4">Subject status</th><th class="p-4">Action</th></tr></thead><tbody>
@forelse($subjects as $subject)
<tr class="border-t border-slate-200"><td class="p-4"><strong>{{ $subject->name }}</strong><span class="subject-code">{{ $subject->code }}</span></td><td class="p-4">{{ $subject->type->value }}</td><td class="p-4">@if($year)<span class="assignment-count {{ $subject->current_classes_count ? '' : 'is-empty' }}">{{ $subject->current_classes_count ? $subject->current_classes_count.' classes' : 'Not assigned' }}</span>@else<span class="text-xs text-slate-500">No current year</span>@endif</td><td class="p-4"><x-status-badge :value="$subject->status->value" /></td><td class="p-4"><div class="table-actions"><x-action-link :href="route('subjects.show',['subject'=>$subject->id,'catalog'=>$catalog])" label="View" /><x-action-link :href="route('class-subjects.index',['subject_id'=>$subject->id,...($year?['academic_year_id'=>$year->id,'status'=>'ACTIVE']:[]),'catalog'=>$catalog])" label="Classes" icon="classes" :primary="true" :aria-label="'View classes for '.$subject->name" /></div></td></tr>
@empty
<tr><td colspan="5" class="p-8 text-center text-slate-500">No subjects match your filters.</td></tr>
@endforelse
</tbody></table></x-data-table></x-app-layout>
