<x-app-layout :title="$subject->name"><a href="{{ route('subjects.index',$workspace['catalog']??[]) }}" class="text-sm font-semibold text-[#8b2f24]">← Subjects</a><div class="mt-4 mb-6 flex flex-wrap items-center justify-between gap-4"><h1 class="text-3xl font-bold">{{ $subject->name }}</h1>
@can('update',$subject)
<a href="{{ route('subjects.edit',['subject'=>$subject->id,...$workspace]) }}" class="rounded-lg bg-[#8b2f24] px-5 py-3 font-semibold text-white">Edit subject</a>
@endcan
</div><x-admin-feedback /><section class="rounded-xl border border-slate-200 bg-white p-6"><dl class="grid gap-6 sm:grid-cols-3"><div><dt class="text-sm text-slate-500">Code</dt><dd class="mt-2 font-semibold">{{ $subject->code }}</dd></div><div><dt class="text-sm text-slate-500">Type</dt><dd class="mt-2 font-semibold">{{ $subject->type->value }}</dd></div><div><dt class="text-sm text-slate-500">Status</dt><dd class="mt-2 font-semibold">{{ $subject->status->value }}</dd></div></dl><div class="table-actions mt-6"><x-action-link :href="route('class-subjects.index',['subject_id'=>$subject->id,...($year?['academic_year_id'=>$year->id]:[]),...$workspace])" label="View classes" icon="classes" />
@can('create',\App\Models\ClassSubject::class)
@if($subject->status->value==='ACTIVE')<x-action-link :href="route('subjects.classes.edit',['subject'=>$subject->id,...($year?['academic_year_id'=>$year->id]:[]),...$workspace])" label="Assign to classes" icon="plus" :primary="true" />
@endif
@endcan</div></section>
@can('update',$subject)
<details class="mt-6 rounded-xl border border-slate-200 bg-white p-6"><summary class="cursor-pointer font-semibold">{{ $subject->status === \App\Enums\SubjectStatus::Active ? 'Deactivate subject across school' : 'Activate subject across school' }}</summary><p class="mt-3 text-sm text-slate-600">{{ $subject->status === \App\Enums\SubjectStatus::Active ? 'Classes using this subject become unavailable for new teaching and exam setup while it is inactive. Their marks settings and history are retained.' : 'Make this subject available again in its active classes and academic years.' }}</p><form method="POST" action="{{ route($subject->status === \App\Enums\SubjectStatus::Active ? 'subjects.deactivate' : 'subjects.activate',$subject) }}" class="mt-4">@csrf<x-context-fields :values="$workspace" /><button class="rounded-lg border border-slate-300 px-5 py-3 font-semibold">Confirm school-wide change</button></form></details>
@endcan
</x-app-layout>
