@props(['active'=>'subjects','catalog'=>[], 'year'=>null])
<nav data-list-sync="subject-workspace-tabs" class="workspace-tabs" aria-label="Subjects workspace">
<a href="{{ route('subjects.index',$catalog) }}" @class(['workspace-tab','is-active'=>$active==='subjects']) 
@if($active==='subjects') aria-current="page" 
@endif><x-icon name="book" /><span>Subjects</span></a>
<a href="{{ route('class-subjects.index',($year?->status->value==='ACTIVE'?['academic_year_id'=>$year->id]:[])+($catalog?['catalog'=>$catalog]:[])) }}" @class(['workspace-tab','is-active'=>$active==='classes'])
@if($active==='classes') aria-current="page" 
@endif><x-icon name="classes" /><span>By class</span></a>
</nav>
