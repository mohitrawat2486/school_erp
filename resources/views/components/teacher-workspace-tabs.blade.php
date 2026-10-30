@props(['active'=>'teachers', 'directory'=>[]])
@if(auth()->user()->can('teacher.view') && auth()->user()->can('teacher_assignment.view'))
<nav data-list-sync="teacher-workspace-tabs" class="workspace-tabs" aria-label="Teachers workspace">
    <a href="{{ route('teachers.index', $directory) }}" @class(['workspace-tab', 'is-active'=>$active === 'teachers']) @if($active === 'teachers') aria-current="page" @endif><x-icon name="teacher" /><span>Teachers</span></a>
    <a href="{{ route('assignments.index', $directory ? ['directory'=>$directory] : []) }}" @class(['workspace-tab', 'is-active'=>$active === 'assignments']) @if($active === 'assignments') aria-current="page" @endif><x-icon name="assignment" /><span>All assignments</span></a>
</nav>
@endif
