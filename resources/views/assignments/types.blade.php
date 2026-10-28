<x-app-layout title="Add Teacher Assignment"><a href="{{ route('assignments.index') }}" class="text-sm font-semibold text-[#8b2f24]">← Teacher Assignments</a><h1 class="mt-4 text-3xl font-bold">Choose assignment type</h1><p class="mt-2 mb-6 text-slate-500">Assign responsibility for a class or a specific subject.</p><x-admin-feedback /><div class="grid gap-5 sm:grid-cols-2">
@foreach(\App\Enums\AssignmentType::cases() as $type)
<a href="{{ route('assignments.create',['assignment_type'=>$type->value]) }}" class="rounded-xl border border-slate-200 bg-white p-6 hover:border-[#8b2f24]"><h2 class="text-lg font-semibold">{{ $type === \App\Enums\AssignmentType::ClassTeacher ? 'Class teacher' : 'Subject teacher' }}</h2><p class="mt-2 text-sm text-slate-500">{{ $type === \App\Enums\AssignmentType::ClassTeacher ? 'Responsibility for a section or the whole class.' : 'Responsibility for an allocated subject in a section or the whole class.' }}</p></a>
@endforeach
</div></x-app-layout>
